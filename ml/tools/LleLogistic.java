import java.io.File;
import java.io.FileWriter;
import java.io.PrintWriter;
import java.util.ArrayList;
import java.util.Locale;
import java.util.Random;

import weka.classifiers.AbstractClassifier;
import weka.classifiers.Classifier;
import weka.classifiers.evaluation.Evaluation;
import weka.classifiers.evaluation.NominalPrediction;
import weka.classifiers.evaluation.Prediction;
import weka.classifiers.functions.Logistic;
import weka.classifiers.meta.CVParameterSelection;
import weka.classifiers.rules.ZeroR;
import weka.core.Attribute;
import weka.core.DenseInstance;
import weka.core.Instance;
import weka.core.Instances;
import weka.core.SerializationHelper;
import weka.core.Utils;
import weka.core.converters.ConverterUtils.DataSource;
import weka.filters.Filter;
import weka.filters.unsupervised.attribute.NominalToBinary;
import weka.filters.unsupervised.attribute.RemoveUseless;
import weka.filters.unsupervised.attribute.ReplaceMissingValues;

/**
 * The Estimated Outcome model as WEKA 3.8.6 builds it: Logistic (ridge
 * regression on the log-likelihood) wrapped in CVParameterSelection, which
 * picks the ridge by 10-fold cross-validation (seed 1) among
 * CANDIDATES. Run from ml/tools (see ml/README.md):
 *
 *   train   <arff> <out-prefix>   version 1: the model, its coefficients and
 *                                 the 10x10 cross-validation the paper reports
 *   vectors <model> <out-csv>     P(PASSED) for a spread of made-up students,
 *                                 to check the app's arithmetic against
 *   compare <arff>...             the same as train, for the trainer checks,
 *                                 written next to each file as .weka.json
 */
public class LleLogistic {

    /** CVParameterSelection's -P: ridge from 0.5 to 10 in 20 steps of 0.5. */
    static final String CANDIDATES = "R 0.5 10.0 20.0";

    static final int FOLDS = 10;
    static final int REPEATS = 10;
    static final double AT_RISK = 0.25;

    public static void main(String[] args) throws Exception {
        Locale.setDefault(Locale.ROOT);

        switch (args[0]) {
            case "train":
                train(args[1], args[2], true);
                break;
            case "compare":
                for (int i = 1; i < args.length; i++) {
                    String prefix = args[i].replaceAll("\\.arff$", "");
                    train(args[i], prefix, false);
                    System.out.println("wrote " + prefix + ".weka.json");
                }
                break;
            case "vectors":
                vectors(args[1], args[2]);
                break;
            default:
                throw new IllegalArgumentException("train | compare | vectors");
        }
    }

    static Instances load(String path) throws Exception {
        Instances data = DataSource.read(path);
        data.setClassIndex(data.numAttributes() - 1);
        return data;
    }

    static CVParameterSelection newModel() throws Exception {
        CVParameterSelection model = new CVParameterSelection();
        model.setClassifier(new Logistic());
        model.setCVParameters(new String[] {CANDIDATES});
        model.setNumFolds(FOLDS);
        model.setSeed(1);
        return model;
    }

    static void train(String arff, String prefix, boolean full) throws Exception {
        Instances data = load(arff);
        int failed = data.classAttribute().indexOfValue("FAILED");

        CVParameterSelection model = newModel();
        model.buildClassifier(data);
        Logistic logistic = (Logistic) model.getClassifier();
        double ridge = Double.parseDouble(Utils.getOption('R', model.getBestClassifierOptions().clone()));

        StringBuilder json = new StringBuilder();
        json.append("{\n");
        json.append("  \"weka_version\": \"").append(weka.core.Version.VERSION).append("\",\n");
        json.append("  \"scheme\": \"weka.classifiers.meta.CVParameterSelection -P \\\"").append(CANDIDATES)
            .append("\\\" -X ").append(FOLDS).append(" -S 1 -W weka.classifiers.functions.Logistic -- -M -1\",\n");
        json.append("  \"dataset\": \"").append(new File(arff).getName()).append("\",\n");
        json.append("  \"rows\": ").append(data.numInstances()).append(",\n");
        json.append("  \"failed_rows\": ").append((int) data.attributeStats(data.classIndex()).nominalCounts[failed]).append(",\n");
        json.append("  \"ridge\": ").append(num(ridge)).append(",\n");
        json.append("  \"candidates\": ").append(candidates(data)).append(",\n");
        json.append("  \"model\": ").append(parameters(data, logistic)).append(",\n");
        json.append("  \"evaluation\": ").append(evaluation(data, failed)).append("\n");
        json.append("}\n");

        write(prefix + (full ? ".json" : ".weka.json"), json.toString());

        if (full) {
            SerializationHelper.write(prefix + ".model", model);
            write(prefix + ".txt", model.toString());
            System.out.println(model);
            System.out.println("ridge chosen: " + num(ridge));
        }
    }

    /**
     * Each candidate ridge and its cross-validated error rate, computed the
     * way CVParameterSelection does (same folds, same order), so the choice
     * can be read: the lowest error wins and a tie keeps the earlier value.
     */
    static String candidates(Instances data) throws Exception {
        Instances copy = new Instances(data);
        copy.randomize(new Random(1));
        copy.stratify(FOLDS);

        StringBuilder out = new StringBuilder("[");
        double lower = 0.5, upper = 10.0;
        double increment = (upper - lower) / (20.0 - 1);
        boolean first = true;

        for (double ridge = lower; ridge <= upper; ridge += increment) {
            Evaluation evaluation = new Evaluation(copy);
            Logistic logistic = new Logistic();
            logistic.setRidge(ridge);

            for (int j = 0; j < FOLDS; j++) {
                Instances train = copy.trainCV(FOLDS, j, new Random(1));
                Instances test = copy.testCV(FOLDS, j);
                Classifier copied = AbstractClassifier.makeCopy(logistic);
                copied.buildClassifier(train);
                evaluation.setPriors(train);
                evaluation.evaluateModel(copied, test);
            }

            out.append(first ? "" : ", ").append("{\"ridge\": ").append(num(ridge))
                .append(", \"error_rate\": ").append(num(evaluation.errorRate())).append("}");
            first = false;
        }

        return out.append("]").toString();
    }

    /**
     * The fitted model in the form the app computes from: the coefficients in
     * the attributes' own units (as Logistic stores them, for class FAILED
     * against PASSED), the training means used to fill a missing value, and
     * the means and standard deviations Logistic standardized with (after
     * the filling, sample SD), which are what the ridge penalty saw.
     */
    static String parameters(Instances data, Logistic logistic) throws Exception {
        Instances filled = new Instances(data);
        ReplaceMissingValues replace = new ReplaceMissingValues();
        replace.setInputFormat(filled);
        filled = Filter.useFilter(filled, replace);

        RemoveUseless useless = new RemoveUseless();
        useless.setInputFormat(filled);
        Instances kept = Filter.useFilter(filled, useless);

        NominalToBinary binary = new NominalToBinary();
        binary.setInputFormat(kept);
        Instances coded = Filter.useFilter(kept, binary);

        double[][] par = logistic.coefficients();
        StringBuilder out = new StringBuilder("{\n");
        out.append("    \"class\": \"FAILED\",\n");
        out.append("    \"intercept\": ").append(num(par[0][0])).append(",\n");
        out.append("    \"attributes\": [");

        int j = 1;
        boolean first = true;
        for (int k = 0; k < coded.numAttributes(); k++) {
            if (k == coded.classIndex()) {
                continue;
            }
            Attribute attribute = coded.attribute(k);
            // NominalToBinary names a two-value nominal "Exam_Type=mockboard"
            // (1 for mockboard, 0 for pretest); a missing one was filled with
            // the training mode before that.
            String[] nameAndValue = attribute.name().split("=", 2);
            Attribute source = data.attribute(nameAndValue[0]);
            int original = source == null ? -1 : source.index();
            double fillWith = meanOrMode(data, original);
            double[] meanSd = meanSd(coded, k);
            double mean = meanSd[0];
            double sd = meanSd[1];

            out.append(first ? "\n" : ",\n").append("      {\"name\": \"").append(nameAndValue[0]).append("\"")
                .append(", \"nominal_one\": ").append(nameAndValue.length == 2 ? "\"" + nameAndValue[1] + "\"" : "null")
                .append(", \"coefficient\": ").append(num(par[j][0]))
                .append(", \"fill_missing_with\": ").append(num(fillWith))
                .append(", \"mean\": ").append(num(mean))
                .append(", \"sd\": ").append(num(sd)).append("}");
            j++;
            first = false;
        }

        out.append("\n    ],\n");
        out.append("    \"removed_as_useless\": [");
        first = true;
        for (int k = 0; k < data.numAttributes(); k++) {
            if (k != data.classIndex() && kept.attribute(data.attribute(k).name()) == null) {
                out.append(first ? "" : ", ").append("\"").append(data.attribute(k).name()).append("\"");
                first = false;
            }
        }
        out.append("]\n  }");

        return out.toString();
    }

    /** Mean and sample SD exactly as Logistic.buildClassifier() computes them (a nominal's value is its index). */
    static double[] meanSd(Instances data, int index) {
        double weights = 0, sum = 0, squares = 0;
        for (int i = 0; i < data.numInstances(); i++) {
            double w = data.instance(i).weight();
            double x = data.instance(i).value(index);
            weights += w;
            sum += w * x;
            squares += w * x * x;
        }
        double mean = sum / weights;
        double sd = weights > 1 ? Math.sqrt(Math.abs(squares - weights * mean * mean) / (weights - 1)) : 0;
        return new double[] {mean, sd};
    }

    static double meanOrMode(Instances data, int index) {
        if (index < 0) {
            return Double.NaN;
        }
        if (data.attribute(index).isNominal()) {
            return Utils.maxIndex(data.attributeStats(index).nominalCounts);
        }
        double sum = 0, count = 0;
        for (int i = 0; i < data.numInstances(); i++) {
            if (!data.instance(i).isMissing(index)) {
                sum += data.instance(i).weight() * data.instance(i).value(index);
                count += data.instance(i).weight();
            }
        }
        return count > 0 ? sum / count : 0.0;
    }

    /**
     * Stratified 10-fold cross-validation repeated with seeds 1 to 10, each
     * repeat's predictions pooled (as the Explorer reports one run), then the
     * mean over the repeats. Everything but the ROC area is read at the
     * at-risk cutoff: P(FAILED) >= 0.25 is "at risk". ZeroR on the same folds
     * is the baseline. The ridge is chosen again inside every training fold,
     * since the whole CVParameterSelection is what is cross-validated.
     */
    static String evaluation(Instances data, int failed) throws Exception {
        double[] model = new double[11];
        double[] zeroR = new double[11];
        StringBuilder perRepeat = new StringBuilder("[");

        for (int seed = 1; seed <= REPEATS; seed++) {
            double[] m = repeat(data, failed, newModel(), seed);
            double[] z = repeat(data, failed, new ZeroR(), seed);
            for (int i = 0; i < m.length; i++) {
                model[i] += m[i] / REPEATS;
                zeroR[i] += z[i] / REPEATS;
            }
            perRepeat.append(seed == 1 ? "" : ", ").append(figures(m, seed));
        }

        return "{\n    \"cutoff_p_failed\": " + num(AT_RISK)
            + ",\n    \"folds\": " + FOLDS + ", \"repeats\": " + REPEATS
            + ",\n    \"model\": " + figures(model, 0)
            + ",\n    \"zero_r\": " + figures(zeroR, 0)
            + ",\n    \"per_repeat\": " + perRepeat.append("]")
            + "\n  }";
    }

    /** roc, recall, false alarm, precision, F, accuracy, kappa, TP, FN, FP, TN */
    static double[] repeat(Instances data, int failed, Classifier classifier, int seed) throws Exception {
        Evaluation evaluation = new Evaluation(data);
        evaluation.crossValidateModel(classifier, data, FOLDS, new Random(seed));

        double tp = 0, fn = 0, fp = 0, tn = 0;
        ArrayList<Prediction> predictions = evaluation.predictions();
        for (Prediction prediction : predictions) {
            double[] distribution = ((NominalPrediction) prediction).distribution();
            boolean atRisk = distribution[failed] >= AT_RISK;
            boolean isFailed = (int) prediction.actual() == failed;
            if (isFailed && atRisk) tp++;
            else if (isFailed) fn++;
            else if (atRisk) fp++;
            else tn++;
        }

        double n = tp + fn + fp + tn;
        double recall = tp + fn > 0 ? tp / (tp + fn) : 0;
        double falseAlarm = fp + tn > 0 ? fp / (fp + tn) : 0;
        double precision = tp + fp > 0 ? tp / (tp + fp) : 0;
        double f = precision + recall > 0 ? 2 * precision * recall / (precision + recall) : 0;
        double observed = (tp + tn) / n;
        double chance = ((tp + fn) * (tp + fp) + (fp + tn) * (fn + tn)) / (n * n);
        double kappa = chance < 1 ? (observed - chance) / (1 - chance) : 0;

        return new double[] {evaluation.areaUnderROC(failed), recall, falseAlarm, precision, f, observed, kappa, tp, fn, fp, tn};
    }

    static String figures(double[] v, int seed) {
        return "{" + (seed > 0 ? "\"seed\": " + seed + ", " : "")
            + "\"roc_area\": " + num(v[0]) + ", \"failed_recall\": " + num(v[1]) + ", \"false_alarm_rate\": " + num(v[2])
            + ", \"failed_precision\": " + num(v[3]) + ", \"failed_f_measure\": " + num(v[4])
            + ", \"accuracy\": " + num(v[5]) + ", \"kappa\": " + num(v[6])
            + ", \"confusion\": {\"failed_at_risk\": " + num(v[7]) + ", \"failed_not_at_risk\": " + num(v[8])
            + ", \"passed_at_risk\": " + num(v[9]) + ", \"passed_not_at_risk\": " + num(v[10]) + "}}";
    }

    /**
     * WEKA's own P(PASSED) for made-up students: every combination of exam
     * type with edge scores, then random ones, a fifth of the scores missing.
     */
    static void vectors(String modelPath, String out) throws Exception {
        CVParameterSelection model = (CVParameterSelection) SerializationHelper.read(modelPath);
        Instances header = load(new File(modelPath).getParentFile().getParent() + "/data/LLE_dataset_all_ExamType.arff");
        int passed = header.classAttribute().indexOfValue("PASSED");
        Random random = new Random(20261101);

        try (PrintWriter writer = new PrintWriter(new FileWriter(out))) {
            StringBuilder head = new StringBuilder();
            for (int k = 0; k < header.numAttributes() - 1; k++) {
                head.append(header.attribute(k).name()).append(",");
            }
            writer.println(head + "p_passed");

            double[][] fixed = {{0, 0, 0, 0, 0, 0}, {100, 100, 100, 100, 100, 100}, {50, 50, 50, 50, 50, 50}, {30, 90, 30, 90, 30, 90}};
            for (int type = 0; type < 2; type++) {
                for (double[] scores : fixed) {
                    writeVector(writer, model, header, type, scores, passed);
                }
                double[] allMissing = {Double.NaN, Double.NaN, Double.NaN, Double.NaN, Double.NaN, Double.NaN};
                writeVector(writer, model, header, type, allMissing, passed);
            }

            for (int i = 0; i < 400; i++) {
                double[] scores = new double[6];
                for (int k = 0; k < 6; k++) {
                    scores[k] = random.nextDouble() < 0.2 ? Double.NaN : Math.round(random.nextDouble() * 1000) / 10.0;
                }
                writeVector(writer, model, header, random.nextInt(2), scores, passed);
            }
        }
    }

    static void writeVector(PrintWriter writer, Classifier model, Instances header, int type, double[] scores, int passed) throws Exception {
        Instance instance = new DenseInstance(header.numAttributes());
        instance.setDataset(header);
        instance.setValue(0, type);
        StringBuilder row = new StringBuilder(header.attribute(0).value(type)).append(",");
        for (int k = 0; k < 6; k++) {
            if (Double.isNaN(scores[k])) {
                instance.setMissing(k + 1);
                row.append(",");
            } else {
                instance.setValue(k + 1, scores[k]);
                row.append(num(scores[k])).append(",");
            }
        }
        instance.setClassMissing();
        writer.println(row + num(model.distributionForInstance(instance)[passed]));
    }

    static String num(double value) {
        return Double.isNaN(value) ? "null" : String.format(Locale.ROOT, "%.17g", value);
    }

    static void write(String path, String content) throws Exception {
        try (PrintWriter writer = new PrintWriter(new FileWriter(path))) {
            writer.print(content);
        }
    }
}
