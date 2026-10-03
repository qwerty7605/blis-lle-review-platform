<?php

namespace App\Support;

class Search
{
    /**
     * Passed as a bound parameter rather than written into the SQL, because a
     * backslash inside a string literal means different things to the two
     * drivers this runs on: MySQL would read '\\' as one character, SQLite as
     * two -- and ESCAPE accepts exactly one.
     */
    private const ESCAPE_CHARACTER = '\\';

    /**
     * Constrain a query to rows where any of $columns contains $term literally.
     *
     * $columns are identifiers the caller hard-codes, never request input --
     * they are interpolated into the SQL, so passing a user-supplied column
     * name here would be an injection. $term is always bound.
     *
     * The ESCAPE clause is what makes escapeLike() mean anything: MySQL treats
     * the backslash as LIKE's escape character by default, SQLite has no
     * default at all, so without saying so explicitly the same pattern matches
     * different rows depending on the driver -- and the test suite (SQLite)
     * would stop telling the truth about production (MySQL).
     *
     * @param  array<int, string>  $columns
     */
    public static function whereAnyContains($query, array $columns, string $term)
    {
        $pattern = '%'.self::escapeLike($term).'%';

        return $query->where(function ($group) use ($columns, $pattern) {
            foreach ($columns as $column) {
                $group->orWhereRaw(
                    $column.' LIKE ? ESCAPE ?',
                    [$pattern, self::ESCAPE_CHARACTER],
                );
            }
        });
    }

    /**
     * Escape a user-typed term so it matches literally inside a LIKE pattern.
     *
     * Binding the term already stops SQL injection, but binding does nothing
     * about LIKE's own metacharacters: "%" and "_" keep their wildcard meaning
     * inside the bound value. Unescaped, a search for "juan_dela" also matches
     * "juanXdela", and a search for "%" matches every row -- so the directory
     * answers a question nobody asked.
     *
     * The escape character is doubled first; doing it after the wildcards
     * would turn the "\" of a "\%" back into an escaped backslash and leave
     * the "%" matching anything again.
     */
    public static function escapeLike(string $term): string
    {
        $escape = self::ESCAPE_CHARACTER;

        return str_replace(
            [$escape, '%', '_'],
            [$escape.$escape, $escape.'%', $escape.'_'],
            $term,
        );
    }
}
