package org.bounce.net;

import java.awt.Component;
import java.net.Authenticator;

/**
 * Stand-in for a class from the "bounce" library, which Weka starts up with
 * (to ask for proxy passwords in its package manager) but which is not inside
 * weka-stable.jar. Without it J48 cannot be trained from the command line.
 */
public class DefaultAuthenticator extends Authenticator {
    public DefaultAuthenticator() {
    }

    public DefaultAuthenticator(Component parent) {
    }
}
