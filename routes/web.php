<?php

// No web pages are served by Laravel. The site itself is the Vue app in
// frontend/, which nginx serves directly (nginx/production.conf); Laravel
// answers only /api (routes/api.php) and the /up health check.
