<?php

/**
 * Base test class for Quiz questions.
 */
class QuizFeedback extends QuizTestCase {

  function setUp($modules = array(), $admin_permissions = array(), $user_permissions = array()) {
    $modules[] = 'truefalse';
    parent::setUp($modules);
  }

  public static function getInfo() {
    return array(
      'name' => t('Quiz feedback'),
      'description' => t('Unit test for Quiz feedback.'),
      'group' => t('Quiz'),
    );
  }

  /**
   * Test question feedback. Note that we are only testing if any feedback
   * displays, each question type has its own tests for testing feedback
   * returned from that question type.
   */
  public function testFeedback() {
    $this->drupalLogin($this->admin);
    $quiz_node = $this->drupalCreateQuiz();

    // 3 questions.
    $question_node1 = $this->drupalCreateNode(array('type' => 'truefalse', 'correct_answer' => 1, 'feedback' => 'Q1Feedback'));
    $this->linkQuestionToQuiz($question_node1, $quiz_node);
    $question_node2 = $this->drupalCreateNode(array('type' => 'truefalse', 'correct_answer' => 1, 'feedback' => 'Q2Feedback'));
    $this->linkQuestionToQuiz($question_node2, $quiz_node);

    // This is a dynamic test that only tests the feedback columns showing up.
    variable_set('quiz_auto_revisioning', 0);

    $review_options = array(
      'attempt' => t('Your answer'),
      'correct' => t('Correct?'),
      'score' => t('Score'),
      'answer_feedback' => t('Feedback'),
      'solution' => t('Correct answer'),
    );

    $this->drupalLogin($this->user);

    // Answer the first question.
    $this->drupalGet("node/{$quiz_node->nid}/take");
    $this->drupalPost(NULL, array(
      "question[$question_node1->nid]" => 1,
      ), t('Next'));

    // Check feedback after the Question
    foreach ($review_options as $option => $text) {
      $quiz_node->review_options = array('question' => array($option => $option));
      node_save($quiz_node);

      $this->drupalGet("node/{$quiz_node->nid}/take/1/feedback");
      $this->assertRaw('<th>' . $text . '</th>');
      foreach ($review_options as $option2 => $text2) {
        if ($option != $option2) {
          $this->assertNoRaw('<th>' . $text2 . '</th>');
        }
      }
    }

    // Feedback only after the quiz.
    $this->drupalGet("node/{$quiz_node->nid}/take/1/feedback");
    $this->drupalPost(NULL, array(), t('Next question'));
    $this->drupalPost(NULL, array(
      "question[$question_node2->nid]" => 1,
      ), t('Finish'));

    // Check feedback after the Quiz
    foreach ($review_options as $option => $text) {
      $quiz_node->review_options = array('end' => array($option => $option));
      node_save($quiz_node);

      $this->drupalGet("node/{$quiz_node->nid}/quiz-results/1/view");
      $this->assertRaw('<th>' . $text . '</th>');
      foreach ($review_options as $option2 => $text2) {
        if ($option != $option2) {
          $this->assertNoRaw('<th>' . $text2 . '</th>');
        }
      }
    }
  }

}
