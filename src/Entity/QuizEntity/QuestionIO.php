<?php

namespace Drupal\quiz\Entity\QuizEntity;

use Drupal\quiz\Entity\QuizEntity;
use PDO;

class QuestionIO {

  private $quiz;

  public function __construct(QuizEntity $quiz) {
    $this->quiz = $quiz;
  }

  /**
   * Retrieves a list of questions (to be taken) for a given quiz.
   *
   * If the quiz has random questions this function only returns a random
   * selection of those questions. This function should be used to decide
   * what questions a quiz taker should answer.
   *
   * This question list is stored in the user's result, and may be different
   * when called multiple times. It should only be used to generate the layout
   * for a quiz attempt and NOT used to do operations on the questions inside of
   * a quiz.
   *
   * @return array[] Array of question info.
   */
  public function getQuestionList() {
    if (QUESTION_CATEGORIZED_RANDOM == $this->quiz->randomization) {
      $questions = $this->buildCategoziedQuestionList();
    }
    return $questions = $this->getRequiredQuestions();
  }

  /**
   * Builds the questionlist for quizzes with categorized random questions
   */
  public function buildCategoziedQuestionList() {
    if (!$question_types = array_keys(quiz_question_get_plugin_info())) {
      return array();
    }

    $questions = array();
    $question_ids = array();
    $total_count = 0;
    foreach ($this->quiz->getTermsByVid() as $term) {
      $query = db_select('quiz_question', 'question');
      if (!empty($question_ids)) {
        $query->condition('question.qid', $question_ids, 'NOT IN');
      }
      $query->join('taxonomy_index', 'tn', 'question.nid = tn.nid');
      $result = $query
        ->fields('question', array('qid', 'vid'))
        ->fields('tn', array('tid'))
        ->condition('question.status', 1)
        ->condition('question.type', $question_types)
        ->condition('question.tid', $term->tid)
        ->range(0, $term->number)
        ->orderRandom()
        ->execute();
      $count = 0;
      while ($question = $result->fetchAssoc()) {
        $count++;
        $question['tid'] = $term->tid;
        $question['number'] = $count + $total_count;
        $questions[] = $question;
        $question_ids[] = $question['qid'];
      }
      $total_count += $count;
      if ($count < $term->number) {
        return array(); // Not enough questions
      }
    }
    return $questions;
  }

  /**
   * @return array
   */
  private function getRequiredQuestions() {
    $select = db_select('quiz_relationship', 'relationship');
    $select->innerJoin('quiz_question', 'question', 'relationship.question_nid = question.qid');

    // Sub relationship
    $cond_1 = 'relationship.qr_pid = sub_relationship.qr_id';
    $cond_2 = 'relationship.qr_pid IS NULL AND relationship.qr_id = sub_relationship.qr_id';
    $select->leftJoin('quiz_relationship', 'sub_relationship', "($cond_1) OR ($cond_2)");

    $select->addField('relationship', 'question_nid', 'qid');
    $select->addField('relationship', 'question_vid', 'vid');
    $select->addField('question', 'type');
    $select->fields('relationship', array('qr_id', 'qr_pid', 'weight'));
    $query = $select
      ->condition('relationship.quiz_vid', $this->quiz->vid)
      ->condition('relationship.question_status', QUESTION_ALWAYS)
      ->condition('question.status', 1)
      ->orderBy('sub_relationship.weight')
      ->orderBy('relationship.weight')
      ->execute();

    // Just to make it easier on us, let's use a 1-based index.
    $i = 1;
    $questions = array();
    while ($question_node = $query->fetchAssoc()) {
      $questions[$i++] = $question_node;
    }

    // Get random questions for the remainder.
    if ($this->quiz->number_of_random_questions > 0) {
      $random_questions = $this->getRandomQuestions();
      $questions = array_merge($questions, $random_questions);

      // Unable to find enough requested random questions.
      if ($this->quiz->number_of_random_questions > count($random_questions)) {
        return array();
      }
    }

    // Shuffle questions if required.
    if ($this->quiz->randomization > 0) {
      shuffle($questions);
    }

    return $questions;
  }

  /**
   * Get an array list of random questions for a quiz.
   *
   * @return array[] Array of nid/vid combos for quiz questions.
   */
  private function getRandomQuestions() {
    $amount = $this->quiz->number_of_random_questions;
    if ($this->quiz->tid > 0) {
      return $this->getRandomTaxonomyQuestionIds($this->quiz->tid, $amount);
    }
    return $this->doGetRandomQuestion($amount);
  }

  private function doGetRandomQuestion($amount) {
    $select = db_select('quiz_relationship', 'relationship');
    $select->join('quiz_question', 'question', 'relationship.question_nid = question.qid');
    $select->addField('relationship.question_nid', 'nid');
    $select->addField('relationship.question_vid', 'vid');
    $select->addExpression(':true', 'random', array(':true' => TRUE));
    $select->addExpression(':number', 'relative_max_score', array(':number' => $this->quiz->max_score_for_random));
    return $select
        ->fields('question', array('type'))
        ->condition('relationship.quiz_vid', $this->quiz->vid)
        ->condition('relationship.quiz_qid', $this->quiz->vid)
        ->condition('relationship.question_status', QUESTION_RANDOM)
        ->condition('question.status', 1)
        ->orderRandom()
        ->range(0, $amount)
        ->execute()
        ->fetchAssoc();
  }

  /**
   * Get all of the question nid/vids by taxonomy term ID.
   *
   * @param int $term_id
   * @param int $amount
   *
   * @return
   *   Array of nid/vid combos, like array(array('nid'=>1, 'vid'=>2)).
   */
  public function getRandomTaxonomyQuestionIds($term_id, $amount) {
    if (!$term_id || !$term = taxonomy_term_load($term_id)) {
      return array();
    }

    // Flatten the taxonomy tree, and just keep term id's.
    $term_ids[] = $term->tid;
    if ($tree = taxonomy_get_tree($term->vid, $term->tid)) {
      foreach ($tree as $term) {
        $term_ids[] = $term->tid;
      }
    }

    // Get all published questions with one of the allowed term ids.
    $query = db_select('question', 'question');
    $query->innerJoin('taxonomy_index', 'tn', 'question.qid = tn.nid');
    $query->addExpression(1, 'random');

    return $query
        ->fields('question', array('nid', 'vid'))
        ->condition('question.status', 1)
        ->condition('tn.tid', $term_ids)
        ->condition('question.type', array_keys(quiz_question_get_types()))
        ->orderRandom()
        ->range(0, $amount)
        ->execute()->fetchAll(PDO::FETCH_ASSOC);
  }

}