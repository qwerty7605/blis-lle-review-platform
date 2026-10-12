import java.io.PrintWriter;
import java.nio.file.Files;
import java.nio.file.Paths;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.List;

import weka.classifiers.trees.J48;
import weka.core.Attribute;
import weka.core.DenseInstance;
import weka.core.Instances;

/**
 * Trains Weka's J48 on each CSV it is given and writes the tree next to it
 * (file.csv -> file.weka.txt), so compare_with_weka.php can check that
 * App\Services\MachineLearning\DecisionTreeTrainer grows the same tree from
 * the same data.
 *
 * J48 runs with its defaults except -S (no subtree raising), which the PHP
 * trainer does not do either. The CSV is read by hand: the last column is the
 * outcome (FAIL or PASS), every other column a number.
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
                    values[i] = Double.parseDouble(cells[i]);
                }
                values[header.length - 1] = cells[header.length - 1].trim().equals("PASS") ? 1 : 0;
                data.add(new DenseInstance(1.0, values));
            }

            J48 tree = new J48();
            tree.setOptions(new String[] {"-S", "-M", "2", "-C", "0.25"});
            tree.buildClassifier(data);

            try (PrintWriter out = new PrintWriter(path.replaceAll("\\.csv$", ".weka.txt"))) {
                out.print(tree.toString());
            }
        }
    }
}
