import java.io.PrintWriter;
import java.nio.file.Files;
import java.nio.file.Paths;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.List;
import java.util.Locale;
import java.util.Random;

import weka.classifiers.Evaluation;
import weka.classifiers.trees.J48;
import weka.core.Attribute;
import weka.core.DenseInstance;
import weka.core.Instances;
import weka.core.Utils;

/**
 * Trains Weka's J48 on each CSV it is given and writes the tree next to it
 * (file.csv -> file.weka.txt), followed by what 10-fold cross-validation with
 * seed 1 scores, so compare_with_weka.php can check that the app's own
 * DecisionTreeTrainer and LetModelEvaluator come to the same tree and the
 * same figures from the same data.
 *
 * J48 runs with the options the model was made with, -C 0.25 -M 2. The CSV is
 * read by hand: the last column is the outcome (FAIL or PASS), every other
 * column a number, or ? where the score is missing.
 *
 * Usage: java -cp weka-stable.jar:. CompareWithWeka compare/*.csv
 */
public class CompareWithWeka {
    public static void main(String[] args) throws Exception {
        for (String path : args) {
            List<String> lines = Files.readAllLines(Paths.get(path));
            String[] header = lines.get(0).split(",");

            ArrayList<Attribute> attributes = new ArrayList<>();
            for (int i = 0; i < header.length - 1; i++) {
                attributes.add(new Attribute(header[i]));
            }
            attributes.add(new Attribute(header[header.length - 1], Arrays.asList("FAIL", "PASS")));

            Instances data = new Instances("exam", attributes, lines.size());
            data.setClassIndex(header.length - 1);

            for (String line : lines.subList(1, lines.size())) {
                if (line.trim().isEmpty()) {
                    continue;
                }

                String[] cells = line.split(",");
                double[] values = new double[header.length];
                for (int i = 0; i < header.length - 1; i++) {
                    values[i] = cells[i].trim().equals("?") ? Utils.missingValue() : Double.parseDouble(cells[i]);
                }
                values[header.length - 1] = cells[header.length - 1].trim().equals("PASS") ? 1 : 0;
                data.add(new DenseInstance(1.0, values));
            }

            String[] options = {"-C", "0.25", "-M", "2"};

            J48 tree = new J48();
            tree.setOptions(options.clone());
            tree.buildClassifier(data);

            J48 fresh = new J48();
            fresh.setOptions(options.clone());
            Evaluation evaluation = new Evaluation(data);
            evaluation.crossValidateModel(fresh, data, 10, new Random(1));

            try (PrintWriter out = new PrintWriter(path.replaceAll("\\.csv$", ".weka.txt"))) {
                out.print(tree.toString());
                out.println();
                out.println(String.format(Locale.ROOT, "CV accuracy=%.2f kappa=%.4f failed_recall=%.2f",
                    evaluation.pctCorrect(), evaluation.kappa(), evaluation.recall(0) * 100));
            }
        }
    }
}
