import weka.classifiers.trees.J48;
import weka.classifiers.trees.j48.ClassifierSplitModel;
import weka.classifiers.trees.j48.ClassifierTree;
import weka.core.DenseInstance;
import weka.core.Instance;
import weka.core.Instances;
import weka.core.SerializationHelper;
import weka.core.Utils;

import java.io.FileWriter;
import java.io.PrintWriter;
import java.lang.reflect.Field;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;
import java.util.Random;

/**
 * Asks the real Weka model what it predicts for a spread of cases and writes
 * them to a CSV, so verify_port.php can check that the tree written into
 * LetPredictionService answers the same.
 *
 * The cases are read off the model itself: scores on either side of every cut
 * in the tree, random ones, and ones with scores missing (written as ?). It
 * also prints each leaf's exact training weight, which is what the PHP tree's
 * `instances` and `errors` are copied from.
 *
 * Usage: java -cp weka-stable.jar:. GenerateTestVectors model.model out.csv
 */
public class GenerateTestVectors {
    public static void main(String[] args) throws Exception {
        Object[] loaded = SerializationHelper.readAll(args[0]);
        J48 tree = null;
        Instances header = null;
        for (Object o : loaded) {
            if (o instanceof J48) tree = (J48) o;
            if (o instanceof Instances) header = (Instances) o;
        }

        int features = header.numAttributes() - 1;

        Field rootField = J48.class.getDeclaredField("m_root");
        rootField.setAccessible(true);
        List<double[]> cuts = new ArrayList<>();
        System.out.println("=== Exact leaves ===");
        walk((ClassifierTree) rootField.get(tree), header, "", cuts);

        List<double[]> cases = new ArrayList<>();
        Random random = new Random(42);

        // Either side of every cut, everything else in the middle or missing.
        for (double[] cut : cuts) {
            for (double offset : new double[] {-10, -0.01, 0, 0.01, 10}) {
                for (double rest : new double[] {75.0, 20.0, Double.NaN}) {
                    double[] values = new double[features];
                    java.util.Arrays.fill(values, rest);
                    values[(int) cut[0]] = cut[1] + offset;
                    cases.add(values);
                }
            }
        }

        // Anything at all.
        for (int i = 0; i < 80; i++) {
            double[] values = new double[features];
            for (int j = 0; j < features; j++) {
                values[j] = Math.round(random.nextDouble() * 100.0 * 100.0) / 100.0;
            }
            cases.add(values);
        }

        // Scores missing, from one to all of them.
        for (int i = 0; i < 80; i++) {
            double[] values = new double[features];
            for (int j = 0; j < features; j++) {
                values[j] = random.nextDouble() < 0.35 ? Double.NaN : Math.round(random.nextDouble() * 100.0);
            }
            cases.add(values);
        }
        double[] nothing = new double[features];
        java.util.Arrays.fill(nothing, Double.NaN);
        cases.add(nothing);

        try (PrintWriter out = new PrintWriter(new FileWriter(args[1]))) {
            StringBuilder head = new StringBuilder();
            for (int j = 0; j < features; j++) head.append(header.attribute(j).name()).append(",");
            out.println(head + "weka_prediction,weka_confidence");

            for (double[] values : cases) {
                Instance instance = new DenseInstance(header.numAttributes());
                instance.setDataset(header);
                StringBuilder line = new StringBuilder();

                for (int j = 0; j < features; j++) {
                    if (Double.isNaN(values[j])) {
                        instance.setMissing(j);
                        line.append("?,");
                    } else {
                        instance.setValue(j, values[j]);
                        line.append(values[j]).append(",");
                    }
                }

                double[] distribution = tree.distributionForInstance(instance);
                int predicted = Utils.maxIndex(distribution);
                String label = header.classAttribute().value(predicted).equals("PASSED") ? "PASS" : "FAIL";

                out.println(line + label + "," + String.format(Locale.ROOT, "%.1f", distribution[predicted] * 100));
            }
        }

        System.out.println("Wrote " + cases.size() + " test cases to " + args[1]);
    }

    private static void walk(ClassifierTree node, Instances header, String indent, List<double[]> cuts) throws Exception {
        ClassifierSplitModel model = node.getLocalModel();

        if (node.isLeaf()) {
            System.out.println(indent + header.classAttribute().value(model.distribution().maxClass())
                + " instances=" + model.distribution().total()
                + " errors=" + model.distribution().numIncorrect());
            return;
        }

        Field attribute = model.getClass().getDeclaredField("m_attIndex");
        Field point = model.getClass().getDeclaredField("m_splitPoint");
        attribute.setAccessible(true);
        point.setAccessible(true);

        int index = attribute.getInt(model);
        double cut = point.getDouble(model);
        cuts.add(new double[] {index, cut});

        ClassifierTree[] sons = node.getSons();
        for (int i = 0; i < sons.length; i++) {
            System.out.println(indent + header.attribute(index).name() + (i == 0 ? " <= " : " > ") + cut);
            walk(sons[i], header, indent + "    ", cuts);
        }
    }
}
