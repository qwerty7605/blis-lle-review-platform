# ML: board exam pass/fail predictor

This folder holds the Weka-trained model, its base dataset, and the tooling
used to check the PHP code the app actually runs against Weka. The app never
loads the `.model` file at runtime: this stack has no Java or Python, so the
tree is written out in PHP and retrained in PHP, and both are checked here
against the real Weka.

## What's here

- `models/New_Model_LLE.model` — the original model: a Weka J48 (C4.5)
  decision tree, options `-C 0.25 -M 2`, trained on `lle_board_exam_clean`
  (156 review-exam records, 30 of them FAILED; a row is one review exam a
  student took, labelled with that student's real board result, so a student
  can appear more than once). Its attributes, in order:
  `reference, management, indexing, selection, cataloging, it, overall,
  board_result {FAILED,PASSED}`. Its whole tree:

  ```
  selection <= 43: FAILED (13.17/2.08)
  selection > 43
  |   it <= 32: FAILED (3.79/0.66)
  |   it > 32: PASSED (139.04/15.78)
  ```

- `data/lle_clean.arff` — the base dataset the model was trained on. **Keep
  it in the project.** Every retraining learns from all of its rows plus the
  graduates confirmed since. Without it "Retrain model" is refused.
- `tools/InspectModel.java` — loads a `.model` file with Weka and prints its
  tree, attributes and options.
- `tools/GenerateTestVectors.java` — asks the real model what it predicts for
  a spread of cases (either side of every cut, random scores, scores missing)
  and writes them to `tools/test_vectors.csv`. Also prints each leaf's exact
  training weight.
- `tools/verify_port.php` — replays `test_vectors.csv` through
  `LetPredictionService` and fails if an outcome or a confidence disagrees
  with the real model.
- `tools/CompareWithWeka.java`, `tools/compare_with_weka.php` — train Weka's
  J48 and the app's own trainer on the same datasets and compare the trees and
  the cross-validation figures.
- `tools/weka-stable.jar` — Weka 3.8.6, from Maven Central. Not needed by the
  running app; only by the tools above.

## Where the app uses this

All in `app/Services/MachineLearning/`:

- `LetPredictionService` — the tree as a nested array (version 1, the
  starting model) and `predict()`, which walks whichever version is in use. A
  missing score is treated as Weka treats `?`: the case goes down both
  branches in the proportion the training data did. It is never read as 0.
- `LetReadinessFeatureBuilder` — a student's exams turned into the model's
  attributes. Each exam is filed under a school year and semester
  (`exams.school_year`, `exams.semester`, set on the exam form, defaulting from
  the date). Within a semester an exam counts once (its latest sitting) and a
  subject's score is the average of its exams. `overall` is the weighted
  average of the subjects that have a score (reference 0.20, management 0.20,
  indexing 0.15, selection 0.15, cataloging 0.20, it 0.10), divided by the
  weights present.
- `BoardExamOutlook` — what a student is shown. Locked until one semester has
  all six subject exams ("Complete all 6 subject exams this semester to unlock
  your result."). Then the latest complete semester is read: years 1 to 3 get
  an early warning ("On track" / "At risk"), year 4 a board readiness
  prediction ("Likely to pass" / "At risk of failing"). Also the subjects the
  model treats as risk indicators (read off the tree in use: for the original,
  selection ≤ 43 and it ≤ 32) and the two lowest subjects.
- `DecisionTreeTrainer` — J48 `-C 0.25 -M 2` in PHP, missing values and
  subtree raising included.
- `LetModelEvaluator`, `JavaRandom` — 10-fold cross-validation with random
  seed 1, dealt into folds the way Weka deals them.
- `LetBaseDataset`, `LetTrainingSetBuilder`, `LetModelTrainer` — retraining.

**Data quality caveat:** 156 records is small, and the tree looks at two of
the six subjects. A prediction is a guide, not a guarantee, and the notes the
pages show say so. Do not strip them out.

## How the model learns from new results

Only when an administrator clicks "Retrain model" on the Licensure page.
Nothing retrains by itself.

1. Staff record each graduate's real board result and exam date (Licensure
   page, `lle_results`).
2. A training row is one graduate with a recorded result: the six scores of
   their last complete semester, which must be in their final school year
   here (their fourth), and the real result. Scores from years 1 to 3 are
   never a row, nor is anything the model itself predicted. Demo accounts are
   left out.
3. The training set is every row of `data/lle_clean.arff` plus those rows.
4. Retraining is refused until there are 10 confirmed results the last
   training did not have.
5. A J48 tree is trained and scored by 10-fold cross-validation, seed 1. The
   baseline is the share of rows that PASSED.
6. The new tree is taken up only if its accuracy is at least the baseline and
   its Kappa at least that of the model in use. Otherwise the current model
   stays and the administrator is told why.
7. Every tree trained is kept as a numbered version in `let_models` (version,
   date, rows, FAILED rows, accuracy, Kappa, FAILED recall, baseline, file
   path, active) with its file under `storage/app/private/let-models/`. The
   original is version 1. An administrator can go back to any version that
   was once in use.

## Checking the PHP code against Weka

Both checks need a JDK, which this project's containers do not have, so the
Java side runs in a throwaway container. From the repo root:

**The port of the model.** Must report 0 mismatches:

```
docker run --rm -v "<absolute path to>/ml:/ml" -w /ml/tools eclipse-temurin:17-jdk sh -c \
  "javac -cp weka-stable.jar InspectModel.java GenerateTestVectors.java && \
   java -cp weka-stable.jar:. InspectModel /ml/models/New_Model_LLE.model && \
   java -cp weka-stable.jar:. GenerateTestVectors /ml/models/New_Model_LLE.model /ml/tools/test_vectors.csv"
docker compose exec -T php php ml/tools/verify_port.php
```

**The trainer and the cross-validation.** Must report ALL MATCH: the same
trees, and the same accuracy, Kappa and FAILED recall, as Weka's J48 on eight
made-up datasets (40 to 1000 rows, up to a fifth of the scores missing):

```
docker compose exec -T php php ml/tools/compare_with_weka.php generate
docker run --rm -v "<absolute path to>/ml:/ml" -w /ml/tools eclipse-temurin:17-jdk sh -c \
  "javac -cp weka-stable.jar org/bounce/net/DefaultAuthenticator.java CompareWithWeka.java && \
   java -Djava.awt.headless=true -cp weka-stable.jar:. CompareWithWeka compare/*.csv"
docker compose exec -T php php ml/tools/compare_with_weka.php check
```

`tools/org/bounce/net/DefaultAuthenticator.java` is a stand-in for a class
Weka wants at start-up that is not inside `weka-stable.jar`.

## Replacing the built-in tree by hand

Only needed to change version 1 itself, from a model trained in Weka:

1. Put the new `.model` file in `ml/models/` and its dataset at
   `ml/data/lle_clean.arff`.
2. Run `InspectModel.java` and `GenerateTestVectors.java` against it (above).
3. Update the `TREE` constant in `LetPredictionService.php` from the printed
   tree and exact leaves, and `LetReadinessFeatureBuilder::SUBJECTS` if the
   attributes changed.
4. Run `verify_port.php`. It must report 0 mismatches before shipping.
