<?php

namespace Drupal\quiz\Form\QuizAnsweringForm;

use Drupal\quiz\Entity\QuizEntity;
use Drupal\quiz\Entity\Result;
use Drupal\quiz\Helper\Quiz\TakingHelper;

class FormSubmission extends TakingHelper {

  private $quiz;
  private $quiz_id;
  private $quiz_uri;

  /** @var Result */
  private $result;
  private $page_number;

  /**
   * @param QuizEntity $quiz
   * @param Result $result
   * @param int $page_number
   */
  public function __construct($quiz, $result, $page_number) {
    $this->quiz = $quiz;
    $this->quiz_id = $quiz->qid;
    $this->quiz_uri = 'quiz/' . $quiz->qid;
    $this->result = $result;
    $this->result_id = $result->result_id;
    $this->page_number = $page_number;

    // Load data about the quiz taker
    $this->taker = db_query('SELECT u.uid, u.mail'
      . ' FROM {users} u'
      . ' JOIN {quiz_results} qnr ON u.uid = qnr.uid'
      . ' WHERE result_id = :result_id', array(':result_id' => $this->result->result_id))->fetch();
  }

  /**
   * Submit handler for "back".
   */
  public function formBackSubmit(&$form, &$form_state) {
    $this->redirect($this->quiz, $this->page_number - 1);
    $item = $this->result->layout[$this->page_number];
    if (!empty($item['qr_pid'])) {
      foreach ($this->result->layout as $item) {
        if ($item['qr_id'] == $item['qr_pid']) {
          $this->redirect($this->quiz, $item['number']);
        }
      }
    }
    $form_state['redirect'] = $this->quiz_uri . '/take/' . $this->getCurrentPageNumber($this->quiz);
  }

  /**
   * Submit action for "leave blank".
   */
  public function formBlankSubmit($form, &$form_state) {
    foreach (array_keys($form_state['input']['question']) as $question_id) {
      // Loop over all question inputs provided, and record them as skipped.
      $question = node_load($question_id);

      // Delete the user's answer.
      _quiz_question_response_get_instance($this->result->result_id, $question)
        ->delete();

      // Mark our question attempt as skipped, reset the correct and points flag.
      $answer = quiz_answer_controller()->loadByResultAndQuestion($this->result->result_id, $question->vid);
      $answer->is_skipped = 1;
      $answer->is_correct = 0;
      $answer->points_awarded = 0;
      $answer->answer_timestamp = REQUEST_TIME;
      entity_save('quiz_result_answer', $answer);

      $this->redirect($this->quiz, $this->result->getNextPageNumber($this->page_number));
    }

    // Advance to next question.
    $form_state['redirect'] = $this->quiz_uri . '/take/' . $this->getCurrentPageNumber($this->quiz);

    if (!isset($this->result->layout[$_SESSION['quiz'][$this->quiz->qid]['current']])) {
      // If this is the last question, finalize the quiz.
      $this->formSubmitFinalizeQuestionAnswering($form, $form_state);
    }
  }

  /**
   * Submit handler for the question answering form.
   *
   * There is no validation code here, but there may be feedback code for
   * correct feedback.
   */
  public function formSubmit($form, &$form_state) {
    if ($time_reached = $this->quiz->time_limit && (REQUEST_TIME > ($this->result->time_start + $this->quiz->time_limit))) {
      // Too late.
      // @todo move to quiz_question_answering_form_validate(), and then put all
      // the "quiz end" logic in a sharable place. We just need to not fire the
      // logic that saves all the users answers.
      drupal_set_message(t('The last answer was not submitted, as the time ran out.'), 'error');
    }
    elseif (!empty($form_state['values']['question'])) {
      foreach (array_keys($form_state['values']['question']) as $question_id) {
        foreach ($this->result->layout as $item) {
          if ($item['nid'] == $question_id) {
            $question_array = $item;
            $current_question = node_load($item['nid'], $item['vid']);
          }
        }

        $_answer = $form_state['values']['question'][$question_id];
        $qi_instance = _quiz_question_response_get_instance($this->result->result_id, $current_question, $_answer);
        $qi_instance->delete();
        $qi_instance->saveResult();
        $response = $qi_instance->toBareObject();
        quiz_result_controller()
          ->getWriter()
          ->saveQuestionResult($this->quiz, $response, array('set_msg' => TRUE, 'question_data' => $question_array));

        // Increment the counter.
        $this->redirect($this->quiz, $this->result->getNextPageNumber($this->page_number));
      }
    }

    // In case we have question feedback, redirect to feedback form.
    $form_state['redirect'] = $this->quiz_uri . '/take/' . $this->getCurrentPageNumber($this->quiz);
    if (!empty($this->quiz->review_options['question']) && array_filter($this->quiz->review_options['question'])) {
      $form_state['redirect'] = $this->quiz_uri . '/take/' . ($this->getCurrentPageNumber($this->quiz) - 1) . '/feedback';
    }

    if ($time_reached || $this->result->isLastPage($this->page_number)) {
      $this->formSubmitLastPage($form, $form_state);
    }
  }

  private function formSubmitLastPage($form, &$form_state) {
    // If this is the last question, finalize the quiz.
    $this->formSubmitFinalizeQuestionAnswering($form, $form_state);
  }

  /**
   * Helper function to finalize a quiz attempt.
   * @see quiz_question_answering_form_submit()
   * @see quiz_question_answering_form_submit_blank()
   */
  private function formSubmitFinalizeQuestionAnswering($form, &$form_state) {
    // No more questions. Score quiz.
    $score = $this->endScoring();

    // Only redirect to question results if there is not question feedback.
    if (empty($this->quiz->review_options['question']) || !array_filter($this->quiz->review_options['question'])) {
      $form_state['redirect'] = "quiz-result/{$this->result->result_id}";
    }

    $this->endActions($score, $_SESSION['quiz'][$this->quiz_id]);

    // Remove all information about this quiz from the session.
    // @todo but for anon, we might have to keep some so they could access
    // results
    // When quiz is completed we need to make sure that even though the quiz has
    // been removed from the session, that the user can still access the
    // feedback for the last question, THEN go to the results page.
    $_SESSION['quiz']['temp']['result_id'] = $this->result->result_id;
    unset($_SESSION['quiz'][$this->quiz_id]);
  }

  /**
   * Score a completed quiz.
   */
  private function endScoring() {
    global $user;

    // Mark all missing answers as blank. This is essential here for when we may
    // have pages of unanswered questions. Also kills a lot of the skip code that
    // was necessary before.
    foreach ($this->result->layout as $qinfo) {
      $current_question = node_load($qinfo['nid'], $qinfo['vid']);

      foreach ($this->result->layout as $question) {
        if ($question['nid'] == $current_question->nid) {
          $question_array = $question;
        }
      }

      // Load the Quiz answer submission from the database.
      if (!$answer = quiz_answer_controller()->loadByResultAndQuestion($this->result->result_id, $qinfo['vid'])) {
        $qi_instance = _quiz_question_response_get_instance($this->result->result_id, $current_question, NULL);
        $qi_instance->delete();
        $response = $qi_instance->toBareObject();
        quiz_result_controller()
          ->getWriter()
          ->saveQuestionResult($this->quiz, $response, array('set_msg' => TRUE, 'question_data' => $question_array));
      }
    }

    $score = quiz_result_controller()->getScoreIO()->calculate($this->quiz, $this->result->result_id);
    if (!isset($score['percentage_score'])) {
      $score['percentage_score'] = 0;
    }
    $this->result->is_evaluated = $score['is_evaluated'];
    $this->result->score = $score['percentage_score'];
    $this->result->time_end = REQUEST_TIME;
    entity_save('quiz_result', $this->result);
    if ($user->uid) {
      $score['passing'] = quiz()->getQuizHelper()->isPassed($user->uid, $this->quiz->qid, $this->quiz->vid);
    }
    else {
      $score['passing'] = $score['percentage_score'] >= $this->quiz->pass_rate;
    }
    return $score;
  }

  /**
   * Actions to take at the end of a quiz
   *
   * @param int $score
   *  Score as a number
   */
  private function endActions($score, $session_data) {
    $this->endActionMailing();
    $this->endActionUserPoint();

    // Call hook_quiz_finished().
    // @TODO consider hook_entity_update if we make quiz results rules capable
    module_invoke_all('quiz_finished', $this->quiz, $score, $session_data);

    // Lets piggy back here to perform the quiz defined action since were done
    // with this quiz.
    // We will verify that there is an associated action with this quiz and then
    // perform that action.
    if (!empty($this->quiz->aid)) {
      // @TODO get rid of this. Replace with rules. Make quiz results entities or
      // something
      // Some actions are reliant on objects and I am unsure which ones, for now I
      // have simply passed the actions_do() function an empty array. By passing
      // this function a single id then it will retrieve the callback, get the
      // parameters and perform that function (action) for you.
      actions_do($this->quiz->aid, $this->quiz, $score, $session_data);
    }

    return $score;
  }

  private function endActionMailing() {
    if (variable_get('quiz_results_to_quiz_author', 0)) {
      $author_mail = db_query('SELECT mail FROM {users} WHERE uid = :uid', array(':uid' => $this->quiz->uid))->fetchField();
      drupal_mail('quiz', 'notice', $author_mail, NULL, array($this->quiz, $this->score, $this->result_id, 'author'));
    }

    if (variable_get('quiz_email_results', 0) && variable_get('quiz_use_passfail', 1) && $this->taker->uid != 0 && $this->score['is_evaluated']) {
      drupal_mail('quiz', 'notice', $this->taker->mail, NULL, array($this->quiz, $this->score, $this->result_id, 'taker'));
      drupal_set_message(t('Your results have been sent to your email address.'));
    }
  }

  /**
   * Calls userpoints functions to credit user point based on number of correct
   * answers.
   *
   * @TODO convert to entity/rules
   */
  private function endActionUserPoint() {
    if (!$this->quiz->has_userpoints || !$this->taker->uid || !$this->score['is_evaluated']) {
      return;
    }

    // Looking up the tid of the selected Userpoint vocabulary
    $selected_tid = db_query("SELECT tid FROM {taxonomy_index}
                WHERE nid = :nid AND tid IN (
                  SELECT tid
                  FROM {taxonomy_term_data} t_t_d JOIN {taxonomy_vocabulary} t_v ON t_v.vid = t_t_d.vid
                  WHERE t_t_d.vid = :vid
                )", array(':nid' => $this->quiz->qid, ':vid' => $this->quiz->vid,
        ':vid' => userpoints_get_vid()))->fetchField();
    $variables = array(
        '@title' => $this->quiz->title,
        '@quiz'  => QUIZ_NAME,
        '@time'  => date('l jS \of F Y h:i:s A'),
    );
    $params = array(
        'points'      => $this->score['numeric_score'],
        'description' => t('Attended @title @quiz on @time', $variables),
        'tid'         => $selected_tid,
        'uid'         => $this->taker->uid,
    );
    if ($this->quiz->userpoints_tid != 0) {
      $params['tid'] = $this->quiz->userpoints_tid;
    }
    userpoints_userpointsapi($params);
  }

  private function getCurrentPageNumber(QuizEntity $quiz) {
    $id = $quiz->qid;
    return isset($_SESSION['quiz'][$id]['current']) ? $_SESSION['quiz'][$id]['current'] : 1;
  }

}
