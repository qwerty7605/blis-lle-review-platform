# ML: LET pass/fail predictor

This folder holds the original Weka-trained model and the tooling used to
turn it into the PHP code the app actually runs. The app itself never loads
`.model` files at runtime — it uses a hand-verified PHP port of the tree
instead, because this stack has no Java or Python runtime.

## What's here

- `models/j48_LLE.model` — the original Weka J48 (C4.5) decision tree,
  trained on one BLIS student batch (4 years of semester grades + mock
  exam subject scores, labeled with each student's actual LET result).
- `tools/InspectModel.java` — loads a `.model` file with Weka and dumps its
  tree structure (`toString()`, `graph()`, attribute list).
- `tools/GenerateTestVectors.java` — generates feature vectors covering
  every leaf of the tree (plus random/edge cases) and classifies them with
  the real Weka model, for regression-testing the PHP port.
- `tools/weka-stable.jar` — Weka 3.8.6, fetched from Maven Central. Not
  needed by the running app; only used by the two tools above.
- `tools/test_vectors.csv` — output of `GenerateTestVectors.java` against
  the current model: 69 cases with Weka's actual prediction for each.
- `tools/verify_port.php` — replays `test_vectors.csv` through
  `App\Services\MachineLearning\LetPredictionService` and fails if any
  prediction disagrees with the real model.

## Where the app actually uses this

`app/Services/MachineLearning/LetPredictionService.php` — a plain PHP
class with the tree hardcoded as a nested array, plus a `predict()` method
that walks it. Exposed at `POST /api/predictions/let` (staff/admin only,
see `routes/api/predictions.php`).

**Data quality caveat** (from the person who trained this model): the
training set is a single small student cohort, so treat predictions as a
rough signal, not a certainty. The API response always includes a
`confidence`/`support` figure per prediction and a disclaimer string —
don't strip those out in any UI that displays this.

## How the model learns from new results

The built-in tree is where the system starts. From then on it retrains
itself, in PHP, from the licensure results recorded on the Licensure page:

1. Saving or removing a result queues `App\Jobs\RetrainLetModelJob`. Only
   one waits at a time, and it holds off until no result has been saved for
   ten minutes, so a batch typed in one student at a time is learned from
   once.
2. `LetTrainingSetBuilder` makes one record per person with a result: their
   15 features (from exams submitted up to the day they sat the board exam)
   and the real outcome. Demo accounts are left out, and so is anyone with
   too few graded exams to describe. Records imported with
   `php artisan let:import-training-data file.csv` are added.
3. `DecisionTreeTrainer` grows a tree from them the way J48 does.
4. `LetModelTrainer` compares that tree with the model in use on the
   results the model in use has never seen. For those, the new tree's
   answers come from cross-validation, so it is not marked on records it
   was trained on. It needs 20 records, 5 of each outcome and 10 unseen.
5. If the new tree does at least as well it is stored in `let_models` as
   `active` and predictions use it from then on
   (`LetPredictionService::liveModel()`); otherwise it is stored as
   `rejected` and nothing changes. An administrator can go back to the
   previous model from the Licensure page, and `php artisan let:retrain`
   runs the whole check by hand.

The original cohort's file is not in this repository. Until it is imported,
a retrained tree knows only this system's own graduates.

### Checking the PHP trainer against Weka

`tools/compare_with_weka.php` and `tools/CompareWithWeka.java` train both on
the same made-up datasets (30 to 1000 students) and compare the trees, which
must come out identical. J48 runs with `-S` (no subtree raising), which the
PHP trainer does not do. From the repo root:

```
docker exec blis-system-php-1 php ml/tools/compare_with_weka.php generate
docker run --rm -v "<absolute path to>/ml:/ml" -w /ml/tools eclipse-temurin:17-jdk sh -c \
  "javac -cp weka-stable.jar org/bounce/net/DefaultAuthenticator.java CompareWithWeka.java && \
   java -Djava.awt.headless=true -cp weka-stable.jar:. CompareWithWeka compare/*.csv"
docker exec blis-system-php-1 php ml/tools/compare_with_weka.php check
```

`tools/org/bounce/net/DefaultAuthenticator.java` is a stand-in for a class
Weka wants at start-up that is not inside `weka-stable.jar`.

## Replacing the built-in tree by hand

Only needed to change the fallback tree itself, from a model trained in Weka:

1. Drop the new `.model` file into `ml/models/`.
2. Run `InspectModel.java` against it (see below) to get the new tree text.
3. Update the `TREE` constant in `LetPredictionService.php` to match, and
   update `requiredFeatures()` if the attribute set changed.
4. Regenerate `test_vectors.csv` with `GenerateTestVectors.java` against
   the new model, then run `verify_port.php` — it must report 0 mismatches
   before shipping.

Both Java tools need a JDK + `weka-stable.jar` on the classpath. No JDK is
installed in this project's containers, so run them via a throwaway
container, e.g. from the repo root:

```
docker run --rm -v "<absolute path to>/ml:/ml" -w /ml/tools eclipse-temurin:17-jdk sh -c \
  "javac -cp weka-stable.jar InspectModel.java GenerateTestVectors.java && \
   java -cp weka-stable.jar:. InspectModel /ml/models/j48_LLE.model && \
   java -cp weka-stable.jar:. GenerateTestVectors /ml/models/j48_LLE.model /ml/tools/test_vectors.csv"
```

Then verify the PHP port from the php container:

```
docker exec blis-system-php-1 sh -c "cd //var/www && php ml/tools/verify_port.php"
```
