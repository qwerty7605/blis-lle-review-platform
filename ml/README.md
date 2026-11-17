# ML: the Estimated Outcome model

This folder holds the WEKA-trained model, its base dataset, and the tooling
used to check the PHP code the app actually runs against WEKA. The app never
loads a `.model` file at runtime: this stack has no Java or Python, so the
model is computed, trained and evaluated in PHP, and all three are checked
here against the real WEKA 3.8.6.

## The model

Ridge-penalized logistic regression, built in WEKA as

```
weka.classifiers.meta.CVParameterSelection -P "R 0.5 10.0 20.0" -X 10 -S 1 \
  -W weka.classifiers.functions.Logistic -- -M -1
```

CVParameterSelection tries the ridge at 0.5, 1.0, ... 10.0 by 10-fold
cross-validation (seed 1) and keeps the one with the fewest errors (the first
on a tie); Logistic is then fitted on every row with it.

- Inputs: `Exam_Type {pretest, mockboard}` and the six subject scores
  (Library Organization and Management, Reference Bibliography and User
  Services, Indexing and Abstracting, Selection and Acquisition, Cataloging
  and Classification, Information Technology). Class: `LLE_Result {FAILED,
  PASSED}`. Once a retraining has battery rows (below) the exam type is
  `Exam_Type {pretest, mockboard, battery}`, which NominalToBinary turns into
  one 0/1 input per type instead of the one `Exam_Type=mockboard`; without
  battery rows it stays exactly as version 1 has it.
- A row is one review exam series (a pre-test or a mock board, all six
  subjects) a student sat, labelled with that student's real LLE result, so
  one examinee gives several rows. A retrained version can also have battery
  rows: one per semester of a graduate's battery exams (all six subjects,
  3rd year on).
- "At risk" means P(FAILED) ≥ 0.25. The card shows P(PASSED) as the chance
  of passing.

### Version 1

Trained on `data/LLE_dataset_all_ExamType.arff` (128 rows, 27 FAILED). The
ridge chosen is 0.5 (it ties with 1.0 and 2.5 at 18 errors of 128; the first
wins). Coefficients for class FAILED, in raw score units:

| Variable | Coefficient | Odds ratio |
|---|---:|---:|
| Exam_Type=mockboard | 2.4513 | 11.603 |
| Library Organization and Management | -0.0302 | 0.9703 |
| Reference Bibliography and User Services | -0.0304 | 0.9700 |
| Indexing and Abstracting | -0.0122 | 0.9879 |
| Selection and Acquisition | -0.1135 | 0.8927 |
| Cataloging and Classification | 0.0275 | 1.0279 |
| Information Technology | -0.0352 | 0.9655 |
| Intercept | 8.8524 | |

Its evaluation: stratified 10-fold cross-validation repeated with seeds 1 to
10, the ridge chosen again inside every training fold; each repeat's 128
predictions pooled, then the ten repeats averaged. Everything except ROC area
is at the 0.25 cutoff.

| Figure | Model | ZeroR |
|---|---:|---:|
| ROC area | 0.8631 | |
| FAILED recall | 0.7148 | |
| False alarm rate | 0.2109 | |
| FAILED precision | 0.4760 | |
| FAILED F-measure | 0.5711 | |
| Accuracy | 0.7734 | 0.7891 |
| Kappa | 0.4256 | 0 |

Mean confusion matrix (actual FAILED / PASSED by predicted FAILED / PASSED):
19.3 / 7.7 and 21.3 / 79.7.

## What's here

- `data/LLE_dataset_all_ExamType.arff` — the base dataset. **Keep it in the
  project.** Every retraining learns from all of its rows plus the graduates
  confirmed since. Without it "Retrain model" is refused.
- `models/logistic-v1.json` — version 1 as WEKA built it: coefficients,
  the filters' fill values, means and standard deviations, the ridge
  candidates and their error rates, and the 10 × 10 evaluation. The
  migration `2026_11_03_000000_rebuild_let_models_for_logistic_regression`
  loads it into `let_models`. `logistic-v1.model` (WEKA's serialized model)
  and `logistic-v1.txt` (WEKA's printout) are kept beside it.
- `tools/LleLogistic.java` — the model in WEKA: `train` builds version 1 and
  its evaluation, `vectors` writes P(PASSED) for a spread of made-up
  students to `tools/logistic_vectors.csv`, `compare` trains and evaluates on
  the made-up datasets of the trainer check.
- `tools/compare_logistic.php` — the same in the app's code, compared with
  WEKA's output (`vectors`, `base`, `generate`, `check`).
- `tools/weka-stable.jar` — WEKA 3.8.6, from Maven Central. Not needed by the
  running app; only by the tools above.
- `tools/org/bounce/net/DefaultAuthenticator.java` — a stand-in for a class
  WEKA wants at start-up that is not inside `weka-stable.jar`.

## Where the app uses this

All in `app/Services/MachineLearning/`:

- `LogisticModel` — one version's numbers and the arithmetic, as WEKA does
  it: a missing score filled with the training mean (ReplaceMissingValues),
  exam type as one 0/1 input (`exam_type`, two types) or one per type
  (`exam_type=pre_test`, `=mock_board`, `=battery`; a missing type takes the
  training mode), each input standardized, then
  P(FAILED) = 1 / (1 + e^-z). `isAtRisk()` compares the unrounded P(FAILED)
  with 0.25. `examTypesFor()` says how a training set is coded (three types
  once any row is a battery row), and the trainer, ridge choice and
  evaluation code every fold the way the whole set is coded, as WEKA's folds
  share their file's header.
- `LetPredictionService` — the version in use (the active row of
  `let_models`) and `predict()`.
- `ReviewSeriesFeatureBuilder` — a student's exams turned into the model's
  inputs. Exams of type pre-test or mock board belong to a series with one
  exam per subject; a series is complete once all six have a submitted
  sitting, and an exam sat twice counts by its latest sitting. Battery and
  regular exams never feed it.
- `BatterySemesterFeatureBuilder` — a student's battery exams turned into
  rows: one per semester (the term the exams are filed under), each subject
  the percent right over every item dealt in that semester's battery exams
  (latest sitting of each, a blank wrong), complete when all six subjects
  are there, 3rd year on only (the semester's school year minus the
  admission year, plus one).
- `BoardExamOutlook` — what the card shows: locked until a series is
  complete, then the most recently completed series. Battery exams do not
  feed the card (yet); they only train.
- `LogisticRegressionTrainer` — Logistic's fit: ridge on the
  standardized inputs, intercept not penalized, minimized by Newton's method.
- `RidgeSelector` — CVParameterSelection's choice of the ridge.
- `LetModelEvaluator`, `WekaFolds`, `JavaRandom` — the repeated
  cross-validation, dealt into folds the way WEKA deals them, ROC area as
  WEKA's ThresholdCurve computes it, and ZeroR on the same folds; also the
  same figures over the review rows and the battery rows apart (`by_type`),
  from the same pooled predictions.
- `LetBaseDataset`, `LetTrainingSetBuilder`, `LetModelTrainer` and
  `app/Jobs/RetrainLetModelJob.php` — retraining.

**Data quality caveat:** 128 rows is small, and 27 of them FAILED. An estimate
is a guide for review planning, not a prediction of the actual LLE, and the
note on the card says so. Do not strip it out.

## How the model learns from new results

Only when an administrator clicks "Retrain model" on the Licensure page.
Nothing retrains by itself.

1. Staff record each graduate's real LLE result and exam date (Licensure
   page, `lle_results`).
2. A training row is one pre-test or mock board series a graduate completed
   before their LLE date (with no date recorded, December 31 of the exam
   year), with its exam type, the six scores and the real result; or one
   semester of battery exams sat before that date, from the 3rd year on,
   with all six subjects (exam type `battery`). Only the graduate's first LLE
   sitting is used. Regular exams are never a row, nor are 1st- and 2nd-year
   battery exams, nor anything the model itself predicted. Demo accounts are
   left out, and so are battery exams of a student with no admission year on
   file.
3. The training set is every row of the base dataset plus those rows.
4. Retraining is refused until there are 10 confirmed results the last
   training did not have.
5. It runs on the queue (it fits about twenty thousand models): the ridge is
   chosen and the model fitted as above, then evaluated 10 × 10 as above.
6. The new version is put into use only if, on its pre-test and mock board
   rows (every row when it has no battery rows), its ROC area is at least
   0.70, its FAILED recall at least 0.60, its Kappa above ZeroR's (0), and
   its ROC area at least that of the version in use. Otherwise it is kept,
   not in use, with the check it failed. Battery rows therefore cannot make
   the Estimated Outcome worse.
7. A version with battery rows is also marked ready for early estimates
   from battery exams (`battery_ready`) only if its battery rows on their own
   reach ROC area 0.70 and FAILED recall 0.60, beat ZeroR on Kappa, and at
   least 10 of the graduates who gave them failed the LLE. That decides
   nothing about step 6. Nothing shows a battery estimate yet.
   A licensure result of "did not take" is never a label: a graduate's first
   sitting is their first pass or fail.
8. Every version is kept in `let_models` with its figures, ridge,
   coefficients and evaluation, and a file under
   `storage/app/private/let-models/`. An administrator can put any version
   that meets both targets into use.

## Checking the PHP code against WEKA

These need a JDK, which this project's containers do not have, so the Java
side runs in a throwaway container. From the repo root, with `ML` the
absolute path to this folder:

```
docker run --rm -u "$(id -u):$(id -g)" -v "$ML:/ml" -w /ml/tools eclipse-temurin:17-jdk sh -c \
  "javac -cp weka-stable.jar org/bounce/net/DefaultAuthenticator.java LleLogistic.java"
```

**Version 1.** Rebuilds `models/logistic-v1.*` from the base dataset (only
needed if the dataset changes), then checks the app's trainer and evaluator
on it against the WEKA export. Must end ALL MATCH:

```
docker run --rm -u "$(id -u):$(id -g)" -v "$ML:/ml" -w /ml/tools eclipse-temurin:17-jdk \
  java -Djava.awt.headless=true -cp weka-stable.jar:. LleLogistic train \
  /ml/data/LLE_dataset_all_ExamType.arff /ml/models/logistic-v1
docker compose exec -T php php ml/tools/compare_logistic.php base
```

**The arithmetic.** P(PASSED) from the app's model against WEKA's for 410
made-up students (missing scores included). Must end ALL MATCH:

```
docker run --rm -u "$(id -u):$(id -g)" -v "$ML:/ml" -w /ml/tools eclipse-temurin:17-jdk \
  java -Djava.awt.headless=true -cp weka-stable.jar:. LleLogistic vectors \
  /ml/models/logistic-v1.model /ml/tools/logistic_vectors.csv
docker compose exec -T php php ml/tools/compare_logistic.php vectors
```

**The trainer and the evaluation.** The same ridge, coefficients and
figures as WEKA on seven made-up datasets (40 to 300 rows, some scores
missing; three of them with battery rows, so a three-value exam type). For
those three it also checks the figures over the review rows and the battery
rows apart (LleLogistic deals the folds itself for that, and first checks
its all-row figures against crossValidateModel's), and for every set the
app's P(PASSED) from WEKA's own model, every exam type and missing values
included. `tools/compare/` is not committed; `generate` writes it. Must end
ALL MATCH:

```
docker compose exec -T php php ml/tools/compare_logistic.php generate
docker run --rm -u "$(id -u):$(id -g)" -v "$ML:/ml" -w /ml/tools eclipse-temurin:17-jdk sh -c \
  "java -Djava.awt.headless=true -cp weka-stable.jar:. LleLogistic compare /ml/tools/compare/logistic_*.arff"
docker compose exec -T php php ml/tools/compare_logistic.php check
```

Last run (2026-11-17, after battery rows were added): all three ALL MATCH;
coefficients within 5.7e-5 of WEKA's on the base dataset and within 2.0e-5
on the made-up sets, evaluation figures (every row, review rows, battery rows,
ZeroR) within 3.4e-16, P(PASSED) identical. `n60_s3_battery` is also the
fixture of `tests/Unit/MachineLearning/BatteryTrainingTest.php`
(`tests/Fixtures/lle_battery_n60.*`).

## Replacing version 1 by hand

Only needed to change version 1 itself, from a new base dataset:

1. Put the dataset at `ml/data/LLE_dataset_all_ExamType.arff` (same
   attributes, same order).
2. Run `LleLogistic train` and `vectors` (above), then the three checks.
3. A database that already ran the migration keeps its old version 1;
   on an empty database `migrate:fresh` loads the new one.
