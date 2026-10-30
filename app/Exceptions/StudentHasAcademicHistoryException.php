<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when something tries to delete a student who has academic history --
 * exam attempts, or a recorded licensure outcome.
 *
 * Deleting such a student is not a routine operation: their attempt history
 * supplies the series scores ReviewSeriesFeatureBuilder reads, and their LLE
 * result is the label that pairs with them. Neither can be regenerated. Archiving
 * (`archived_at`) is the supported way to take someone out of circulation.
 */
class StudentHasAcademicHistoryException extends RuntimeException
{
}
