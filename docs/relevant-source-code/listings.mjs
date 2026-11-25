// The excerpts in the Relevant Source Code appendix, in order, grouped by
// section. Each range is [first line, last line] of the file AT THE RELEASE TAG
// (build.mjs reads the file with `git show <tag>:<file>`), and `starts` is text
// its first line must contain: if the code moved, the build stops instead of
// quoting the wrong lines. Lines between two ranges are left out and marked;
// `omitAfter` marks the rest of a function left out after the last range.

export const SECTIONS = [
  {
    title: 'Estimated Outcome: the prediction model',
    intro:
      'These listings implement the model that estimates each student\'s chance of passing the Librarians\' Licensure Examination (LLE): how a probability is computed from the stored coefficients, how a new version is trained and validated, and the WEKA program used to check that the PHP code reproduces WEKA\'s results.',
    listings: [
      {
        title: 'Computing the chance of passing and the at-risk flag',
        file: 'app/Services/MachineLearning/LogisticModel.php',
        ranges: [[155, 174, 'function probabilityOfFailing'], [248, 271, 'private function linear']],
        text: [
          'The model is a ridge-penalised logistic regression. linear() adds the intercept and each input times its coefficient; a subject score the student does not have is replaced by its mean in the training data, as WEKA\'s ReplaceMissingValues filter does.',
          'probabilityOfFailing() applies the logistic function to that sum, and isAtRisk() flags a student whose probability of failing is 0.25 or more (AT_RISK_CUTOFF), decided on the unrounded value.',
        ],
      },
      {
        title: 'The penalised log-likelihood minimised in training',
        file: 'app/Services/MachineLearning/LogisticRegressionTrainer.php',
        ranges: [[244, 264, 'private function objective']],
        text: [
          'Training finds the coefficients that minimise this function: the negative log-likelihood of the training records plus the ridge penalty (the ridge value times the sum of the squared coefficients, the intercept excluded). log(1 + e^z) is written so that it cannot overflow for large scores.',
        ],
      },
      {
        title: 'Choosing the ridge value by cross-validation',
        file: 'app/Services/MachineLearning/RidgeSelector.php',
        ranges: [[66, 112, 'public function select(']],
        text: [
          'The ridge value is chosen as WEKA\'s CVParameterSelection chooses it: each of the 20 candidates from 0.5 to 10.0 is scored by its error rate (the share of records misclassified) in 10-fold cross-validation on the same folds WEKA uses (seed 1), and the candidate with the lowest error is kept, the earlier candidate winning a tie.',
        ],
      },
      {
        title: 'Evaluating a model version by repeated cross-validation',
        file: 'app/Services/MachineLearning/LetModelEvaluator.php',
        ranges: [[51, 102, 'public function evaluate(']],
        text: [
          'A version is judged by 10-fold cross-validation repeated with seeds 1 to 10. Each repeat pools its predictions; the ROC area, FAILED-class recall, precision and Kappa are averaged over the repeats and compared with ZeroR (always predicting the majority class) on the same folds.',
        ],
      },
      {
        title: 'Accepting or rejecting a retrained version',
        file: 'app/Services/MachineLearning/LetModelTrainer.php',
        ranges: [[128, 185, 'public function run(']],
        omitAfter: 266,
        text: [
          'Retraining runs only when an administrator asks for it and at least ten new LLE results have been recorded. The new version goes into use only if every check passes: ROC area of at least 0.70, FAILED recall of at least 0.60 at the 25% cutoff, a Kappa above ZeroR\'s, and an ROC area no lower than the version in use. Otherwise it is kept, inactive, with the checks it failed.',
        ],
      },
      {
        title: 'The WEKA reference model used to validate the PHP code',
        file: 'ml/tools/LleLogistic.java',
        ranges: [[83, 124, 'static CVParameterSelection newModel']],
        text: [
          'Version 1 of the model was trained in WEKA 3.8.6 with this Java program, using CVParameterSelection around Logistic exactly as above. Its coefficients and evaluation figures are the reference that the PHP trainer, ridge selection and cross-validation reproduce to four decimal places (ml/README.md describes the checks).',
        ],
      },
      {
        title: 'When a student\'s Estimated Outcome appears',
        file: 'app/Services/MachineLearning/BoardExamOutlook.php',
        ranges: [[43, 97, 'public function for(']],
        text: [
          'A student sees an estimate only after finishing all six subject exams of one pre-test or mock board series; until then the card says what is still missing. The estimate is read from the most recently completed series, using the latest sitting of any exam taken twice.',
        ],
      },
    ],
  },
  {
    title: 'Learning analytics and question tagging',
    intro:
      'These listings track what each student has mastered, tag every question with its subject and topic in the LLE Table of Specifications, and read the correct answers from teachers\' Word files.',
    listings: [
      {
        title: 'Topic mastery by Bayesian Knowledge Tracing',
        file: 'app/Services/MachineLearning/KnowledgeTracingService.php',
        ranges: [[95, 155, 'public function traceExams(']],
        text: [
          'Each exam is one learning opportunity per topic. The evidence of the topic\'s items in that exam (how likely each answer is if the student knows the topic, allowing a slip, or does not, allowing a guess) is combined by Bayes\' rule, weighted by min(1, 3 / n) for a topic with n items so that a long exam does not dominate, and then one learning step is applied. The prior is 0.25, the slip 0.10, the learning rate 0.15, and the guess rate one over the number of choices.',
        ],
      },
      {
        title: 'Tagging a question with its topic',
        file: 'app/Services/QuestionClassification/LocalQuestionClassifier.php',
        ranges: [[119, 150, 'public function classifyTopic('], [223, 251, 'private function scoreTopicsForSubject(']],
        text: [
          'Each topic of the Table of Specifications has weighted keyword phrases. A question is scored against every topic of its subject; the best topic is taken when its confidence is at least 0.45, otherwise the tag waits for a teacher to confirm it, and a question that matches no phrase is filed under Other / Unclassified.',
        ],
      },
      {
        title: 'Finding the correct answers in a teacher\'s Word file',
        file: 'app/Services/Library/Docx/AnswerMarkResolver.php',
        ranges: [[45, 133, 'public function resolveMarkedAnswers(']],
        text: [
          'Teachers mark the correct choice in different ways: highlighting, shading, a text colour, bold or underline. A mark is accepted as the answer mark when it sits on exactly one choice per question in at least 5% of the questions (30% for bold and underline, which are also used for emphasis). A question with the mark on two choices gets no answer and a warning.',
        ],
      },
    ],
  },
  {
    title: 'Examinations',
    intro:
      'These listings keep an examination reliable when a whole class sits it at once: one attempt per student even under repeated requests, the same mix of questions for everyone, scoring, time limits and automatic submission.',
    listings: [
      {
        title: 'Starting an attempt safely',
        file: 'app/Http/Controllers/Api/ExamAttemptController.php',
        ranges: [[129, 210, 'DB::transaction(function'], [241, 272, '$startedAt = now();']],
        text: [
          'The start runs in one database transaction that first locks the student\'s own row, so two requests at once (Start pressed twice, the exam open in two tabs) cannot create two attempts. Inside it, an attempt already under way is resumed, the closing time and attempt limit are checked, and the questions are dealt: so many from each question set, in random order when the exam shuffles.',
        ],
      },
      {
        title: 'Scoring an attempt',
        file: 'app/Services/ExamAttemptService.php',
        ranges: [[21, 49, 'public function refreshMetrics('], [124, 139, 'public function autoSubmitExpiredAttempt(']],
        text: [
          'refreshMetrics() counts the answered and correct items of an attempt and stores its score; a blank counts as wrong. autoSubmitExpiredAttempt() hands in an attempt whose deadline has passed, timed at the deadline.',
        ],
      },
      {
        title: 'An attempt\'s deadline',
        file: 'app/Models/Exam.php',
        ranges: [[331, 340, 'public function attemptDeadline(']],
        text: [
          'An attempt ends after the exam\'s duration plus any extra time a teacher gave that student, or at the exam\'s end time when the exam is set to end for everyone at its close.',
        ],
      },
      {
        title: 'Submitting abandoned attempts every minute',
        file: 'app/Console/Commands/SubmitExpiredAttemptsCommand.php',
        ranges: [[25, 70, 'public function handle(']],
        text: [
          'The scheduler runs this command every minute. It submits every attempt past its deadline, so an attempt a student left open does not stay "in progress" without a score, and records each one in the audit log.',
        ],
      },
      {
        title: 'Saving answers as the student goes, and the timer',
        file: 'frontend/src/views/dashboard/composables/useStudentExamAttempt.js',
        ranges: [[236, 260, 'function startStudentExamTimer('], [793, 827, 'async function saveStudentExamAnswer(']],
        text: [
          'On the student\'s side each answer is sent to the server as soon as it is chosen, so nothing is lost if the browser closes, and the countdown starts from the time left that the server reports for the attempt, so a wrong clock on the device does not change it.',
        ],
      },
    ],
  },
  {
    title: 'Passing mark and Review Bot',
    intro:
      'These listings judge scores against the passing mark the administrator sets, and decide which practice questions each student may use.',
    listings: [
      {
        title: 'The students\' passing mark and the teachers\' grade',
        file: 'app/Support/PassingMark.php',
        ranges: [[75, 111, 'public function passes(']],
        text: [
          'Students are judged against the mark the administrator sets, from 75 to 95. Teachers and administrators see each score as a grade on the usual scale where 75 passes: below the mark, score × 75 / mark; from the mark, 75 + (score − mark) × 25 / (100 − mark). A grade therefore reaches 75 exactly when the score reaches the mark.',
        ],
      },
      {
        title: 'Which practice questions a student may use',
        file: 'app/Services/ReviewBotEligibility.php',
        ranges: [[62, 87, 'public function sourceIds(']],
        text: [
          'A student practises only Review Bot questions built from items they were given in their own submitted exams, and only once that exam\'s correct answers may be shown to them: results visible, and, when answers are held to the last attempt, no retake left.',
        ],
      },
    ],
  },
  {
    title: 'Security and accountability',
    intro:
      'These listings protect accounts and record what happens in the system.',
    listings: [
      {
        title: 'The sign-in token in an HttpOnly cookie',
        file: 'app/Services/AuthTokenCookieService.php',
        ranges: [[48, 81, 'public function hasGoneIdle(']],
        text: [
          'The sign-in token is kept in an HttpOnly, SameSite=strict cookie that page scripts cannot read. Without "Remember me" the cookie ends with the browser and the token is refused after 8 hours unused; with it, the token lasts 7 days.',
        ],
      },
      {
        title: 'Disabled accounts are signed out',
        file: 'app/Http/Middleware/EnsureAccountIsActive.php',
        ranges: [[24, 37, 'public function handle(']],
        text: [
          'Every signed-in request passes through this check. An account an administrator has disabled is signed out at once and receives "Account is disabled."',
        ],
      },
      {
        title: 'Request limits',
        file: 'app/Providers/AppServiceProvider.php',
        ranges: [[172, 186, 'private function configureRateLimiting('], [248, 264, "RateLimiter::for('api'"]],
        text: [
          'Sign-in is limited to 5 tries a minute per account, which slows password guessing, and 120 a minute per network address, sized so that a whole campus behind one address can still sign in. Other requests are limited per signed-in user.',
        ],
      },
      {
        title: 'The audit log',
        file: 'app/Services/AuditLogService.php',
        ranges: [[16, 40, 'public function record(']],
        text: [
          'Sign-ins and every change to accounts, rooms, exams, question sets, results and settings are written to the audit log with who did it, when, from which address and browser. Nothing in the system edits or deletes an entry.',
        ],
      },
    ],
  },
]
