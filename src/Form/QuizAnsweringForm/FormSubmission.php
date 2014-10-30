<?php

namespace Drupal\quiz\Form\QuizAnsweringForm;

use Drupal\quiz\Entity\QuizEntity;
use Drupal\quiz\Entity\Result;
use Drupal\quiz\Helper\Quiz\QuestionHelper;
use stdClass;

class FormSubmission extends QuestionHelper {

  private $quiz;
  private $quiz_id;
  private $quiz_uri;

  /** @var Result */
  private $result;
  private $page_number;

  /**
   * @param QuizEntity $quiz
   * @param stdClass $result
   * @param int $page_number
   */
  public function __construct($quiz, $result, $page_number) {
    $this->quiz = $quiz;
    $this->quiz_id = $quiz->qid;
    $this->quiz_uri = 'quiz/' . $quiz->qid;
    $this->result = $result;
    $this->page_number = $page_number;
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
      $qra = quiz_result_answer_load($this->result->result_id, $question->nid, $question->vid);
      $qra->is_skipped = 1;
      $qra->is_correct = 0;
      $qra->points_awarded = 0;
      $qra->answer_timestamp = REQUEST_TIME;
      entity_save('quiz_result_answer', $qra);

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
  public function formSubmit(&$form, &$form_state) {
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
          }
        }

        $_question = node_load($question_id);
        $_answer = $form_state['values']['question'][$question_id];
        $qi_instance = _quiz_question_response_get_instance($this->result->result_id, $_question, $_answer);
        $qi_instance->delete();
        $qi_instance->saveResult();
        $result = $qi_instance->toBareObject();
        quiz()
          ->getQuizHelper()
          ->saveQuestionResult($this->quiz, $result, array('set_msg' => TRUE, 'question_data' => $question_array));

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
    $score = quiz_end_scoring($_SESSION['quiz'][$this->quiz->qid]['result_id']);

    // Only redirect to question results if there is not question feedback.
    if (empty($this->quiz->review_options['question']) || !array_filter($this->quiz->review_options['question'])) {
      $form_state['redirect'] = "quiz-result/{$this->result->result_id}";
    }

    quiz_end_actions($this->quiz, $score, $_SESSION['quiz'][$this->quiz_id]);

    // Remove all information about this quiz from the session.
    // @todo but for anon, we might have to keep some so they could access
    // results
    // When quiz is completed we need to make sure that even though the quiz has
    // been removed from the session, that the user can still access the
    // feedback for the last question, THEN go to the results page.
    $_SESSION['quiz']['temp']['result_id'] = $this->result->result_id;
    unset($_SESSION['quiz'][$this->quiz_id]);
  }

}