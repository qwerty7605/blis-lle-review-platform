import weka.classifiers.trees.J48;
import weka.core.Instances;
import weka.core.SerializationHelper;

public class InspectModel {
    public static void main(String[] args) throws Exception {
        String path = args[0];

        Object[] loaded = SerializationHelper.readAll(path);

        System.out.println("=== Loaded " + loaded.length + " object(s) ===");
        for (Object o : loaded) {
            System.out.println("Object class: " + o.getClass().getName());
        }

        J48 tree = null;
        Instances header = null;

        for (Object o : loaded) {
            if (o instanceof J48) {
                tree = (J48) o;
            } else if (o instanceof Instances) {
                header = (Instances) o;
            }
        }

        if (tree == null) {
            System.out.println("No J48 classifier found in the serialized file.");
            return;
        }

        System.out.println("\n=== J48 toString() ===");
        System.out.println(tree.toString());

        System.out.println("\n=== J48 graph() (dot format) ===");
        System.out.println(tree.graph());

        if (header != null) {
            System.out.println("\n=== Instances header (attributes) ===");
            System.out.println(header.toString());
            System.out.println("\nClass attribute: " + header.classAttribute());
            System.out.println("Num attributes: " + header.numAttributes());
            for (int i = 0; i < header.numAttributes(); i++) {
                System.out.println("  [" + i + "] " + header.attribute(i));
            }
        } else {
            System.out.println("\n(no separate Instances header object found in file)");
        }

        System.out.println("\n=== J48 options ===");
        System.out.println(String.join(" ", tree.getOptions()));

        System.out.println("\n=== Measure info ===");
        System.out.println("Number of leaves: " + tree.measureNumLeaves());
        System.out.println("Tree size: " + tree.measureTreeSize());
    }
}
