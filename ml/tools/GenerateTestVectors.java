import weka.classifiers.trees.J48;
import weka.core.DenseInstance;
import weka.core.Instance;
import weka.core.Instances;
import weka.core.SerializationHelper;

import java.io.FileWriter;
import java.io.PrintWriter;
import java.util.*;

public class GenerateTestVectors {
    public static void main(String[] args) throws Exception {
        String modelPath = args[0];
        String outCsvPath = args[1];

        Object[] loaded = SerializationHelper.readAll(modelPath);
        J48 tree = null;
        Instances header = null;
        for (Object o : loaded) {
            if (o instanceof J48) tree = (J48) o;
            if (o instanceof Instances) header = (Instances) o;
        }

        String[] featureNames = {
            "sem1", "sem2", "sem3", "sem4", "sem5", "sem6", "sem7", "sem8",
            "mock_catclass", "mock_indexabs", "mock_it", "mock_reference",
            "mock_libmgmt", "mock_selacq", "mock_total"
        };

        List<Map<String, Double>> cases = new ArrayList<>();

        // Baseline "mid" values for every feature not being specifically probed.
        double MID = 75.0;

        // Helper to build a full feature map from overrides.
        // (done inline below per case)

        // Case 1: mock_total <= 57.39, sem8 <= 79.83 -> FAIL
        cases.add(baseCase(MID, Map.of("mock_total", 50.0, "sem8", 70.0)));
        // Case 2: mock_total <= 57.39, sem8 > 79.83 -> PASS
        cases.add(baseCase(MID, Map.of("mock_total", 50.0, "sem8", 90.0)));
        // Case 3: mock_total>57.39, sem8<=86.41, mock_catclass<=70.62, sem1<=67.77, sem5<=83.51 -> PASS
        cases.add(baseCase(MID, Map.of("mock_total", 65.0, "sem8", 80.0, "mock_catclass", 60.0, "sem1", 60.0, "sem5", 70.0)));
        // Case 4: ... sem5 > 83.51 -> FAIL
        cases.add(baseCase(MID, Map.of("mock_total", 65.0, "sem8", 80.0, "mock_catclass", 60.0, "sem1", 60.0, "sem5", 90.0)));
        // Case 5: ... sem1>67.77, mock_indexabs<=61.26, mock_catclass<=58.71 -> FAIL
        cases.add(baseCase(MID, Map.of("mock_total", 65.0, "sem8", 80.0, "mock_catclass", 55.0, "sem1", 70.0, "mock_indexabs", 55.0)));
        // Case 6: ... mock_catclass>58.71, mock_it<=60.59 -> PASS
        cases.add(baseCase(MID, Map.of("mock_total", 65.0, "sem8", 80.0, "mock_catclass", 65.0, "sem1", 70.0, "mock_indexabs", 55.0, "mock_it", 55.0)));
        // Case 7: ... mock_it>60.59, mock_libmgmt<=69.79 -> FAIL
        cases.add(baseCase(MID, Map.of("mock_total", 65.0, "sem8", 80.0, "mock_catclass", 65.0, "sem1", 70.0, "mock_indexabs", 55.0, "mock_it", 65.0, "mock_libmgmt", 60.0)));
        // Case 8: ... mock_libmgmt>69.79 -> PASS
        cases.add(baseCase(MID, Map.of("mock_total", 65.0, "sem8", 80.0, "mock_catclass", 65.0, "sem1", 70.0, "mock_indexabs", 55.0, "mock_it", 65.0, "mock_libmgmt", 75.0)));
        // Case 9: ... mock_indexabs>61.26 -> PASS
        cases.add(baseCase(MID, Map.of("mock_total", 65.0, "sem8", 80.0, "mock_catclass", 60.0, "sem1", 70.0, "mock_indexabs", 70.0)));
        // Case 10: mock_catclass>70.62, mock_selacq<=72.23 -> PASS
        cases.add(baseCase(MID, Map.of("mock_total", 65.0, "sem8", 80.0, "mock_catclass", 80.0, "mock_selacq", 60.0)));
        // Case 11: mock_selacq>72.23, sem1<=80.08, sem7<=85.18, sem5<=84.42 -> PASS
        cases.add(baseCase(MID, Map.of("mock_total", 65.0, "sem8", 80.0, "mock_catclass", 80.0, "mock_selacq", 80.0, "sem1", 70.0, "sem7", 80.0, "sem5", 80.0)));
        // Case 12: ... sem5>84.42 -> FAIL
        cases.add(baseCase(MID, Map.of("mock_total", 65.0, "sem8", 80.0, "mock_catclass", 80.0, "mock_selacq", 80.0, "sem1", 70.0, "sem7", 80.0, "sem5", 90.0)));
        // Case 13: ... sem7>85.18 -> PASS
        cases.add(baseCase(MID, Map.of("mock_total", 65.0, "sem8", 80.0, "mock_catclass", 80.0, "mock_selacq", 80.0, "sem1", 70.0, "sem7", 90.0)));
        // Case 14: ... sem1>80.08 -> FAIL
        cases.add(baseCase(MID, Map.of("mock_total", 65.0, "sem8", 80.0, "mock_catclass", 80.0, "mock_selacq", 80.0, "sem1", 90.0)));
        // Case 15: sem8>86.41 -> PASS
        cases.add(baseCase(MID, Map.of("mock_total", 65.0, "sem8", 90.0)));

        // Plus some pseudo-random cases for extra coverage.
        Random rnd = new Random(42);
        for (int i = 0; i < 40; i++) {
            Map<String, Double> m = new HashMap<>();
            for (String f : featureNames) {
                m.put(f, Math.round(rnd.nextDouble() * 100.0 * 100.0) / 100.0);
            }
            cases.add(m);
        }

        // Edge cases exactly at / near thresholds
        double[] thresholds = {57.39, 79.83, 86.41, 70.62, 67.77, 83.51, 61.26, 58.71, 60.59, 69.79, 72.23, 80.08, 85.18, 84.42};
        for (double t : thresholds) {
            Map<String, Double> m1 = baseCase(MID, Map.of());
            // vary mock_total around threshold as a generic probe too
            for (String f : featureNames) m1.put(f, MID);
            m1.put("mock_total", t);
            cases.add(new HashMap<>(m1));
        }

        try (PrintWriter pw = new PrintWriter(new FileWriter(outCsvPath))) {
            pw.println(String.join(",", featureNames) + ",weka_prediction");
            for (Map<String, Double> c : cases) {
                Instance inst = new DenseInstance(header.numAttributes());
                inst.setDataset(header);
                for (int i = 0; i < featureNames.length; i++) {
                    inst.setValue(header.attribute(featureNames[i]), c.get(featureNames[i]));
                }
                double clsIdx = tree.classifyInstance(inst);
                String clsLabel = header.classAttribute().value((int) clsIdx);

                StringBuilder sb = new StringBuilder();
                for (String f : featureNames) {
                    sb.append(c.get(f)).append(",");
                }
                sb.append(clsLabel);
                pw.println(sb.toString());
            }
        }

        System.out.println("Wrote " + cases.size() + " test cases to " + outCsvPath);
    }

    private static Map<String, Double> baseCase(double mid, Map<String, Double> overrides) {
        String[] featureNames = {
            "sem1", "sem2", "sem3", "sem4", "sem5", "sem6", "sem7", "sem8",
            "mock_catclass", "mock_indexabs", "mock_it", "mock_reference",
            "mock_libmgmt", "mock_selacq", "mock_total"
        };
        Map<String, Double> m = new HashMap<>();
        for (String f : featureNames) m.put(f, mid);
        m.putAll(overrides);
        return m;
    }
}
