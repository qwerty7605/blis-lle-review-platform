<?php

namespace Database\Seeders\Demo;

use Illuminate\Support\Str;

/**
 * Filipino names for demo accounts, in the shape registration stores them:
 * first name (often two given names), the mother's maiden surname as the
 * middle name, and a surname -- with `name` spelled out in full, the way the
 * sign-up form joins them.
 *
 * Draws from mt_rand, so a seeder that calls mt_srand() first gets the same
 * people on every run. Addresses stay unique by numbering repeats, the way a
 * school's mail system would (juan.delacruz, juan.delacruz2, ...).
 */
final class DemoNames
{
    private const FEMALE = [
        'Maria Angelica', 'Kristine Joy', 'Mary Grace', 'Angelica', 'Jasmine', 'Nicole', 'Camille',
        'Patricia', 'Rhea', 'Joanna', 'Aileen', 'Jessa Mae', 'Princess', 'Hazel', 'Janine', 'Mariel',
        'Christine', 'Shiela', 'Katrina', 'Bea', 'Rachelle', 'Sofia', 'Andrea', 'Lovely', 'Charmaine',
        'Grace', 'Mikaela', 'Danica', 'Ella', 'Faith', 'Gwen', 'Irish', 'Jenny', 'Lyka', 'Mica',
        'Pauline', 'Queenie', 'Rica', 'Trisha', 'Vanessa', 'Althea', 'Bianca', 'Clarisse', 'Dianne',
        'Erica', 'Frances', 'Hannah', 'Ivy', 'Kimberly', 'Leah', 'Marjorie', 'Nina', 'Rose Ann',
        'Stephanie', 'Yvonne', 'Anne Margaret', 'Joy Ann', 'Mary Joy', 'Precious Mae', 'Kaye Anne',
        'Cherry Mae', 'April Joy', 'Ma. Lourdes', 'Ma. Theresa', 'Lorraine', 'Jennifer', 'Rowena',
        'Divina', 'Maricar', 'Julie Ann', 'Arlene', 'Kathleen', 'Czarina', 'Ericka Mae', 'Jeanelle',
    ];

    private const MALE = [
        'Mark Anthony', 'John Paul', 'Christian', 'Jerome', 'Kenneth', 'Carlo', 'Miguel', 'Paolo',
        'Rafael', 'Jose Mari', 'Juan Carlos', 'John Lloyd', 'Joshua', 'Kevin', 'Aldrin', 'Bryan',
        'Cedric', 'Darwin', 'Edgar', 'Francis', 'Gerald', 'Harold', 'Ian', 'Jayson', 'Jomar', 'Karl',
        'Lester', 'Marvin', 'Neil', 'Oliver', 'Patrick', 'Rey', 'Ronald', 'Sherwin', 'Tristan',
        'Vincent', 'Adrian', 'Arnel', 'Benedict', 'Dominic', 'Emmanuel', 'Gabriel', 'Jericho', 'Kyle',
        'Lorenzo', 'Nathaniel', 'Renz', 'Symon', 'Jhon Rey', 'Ralph', 'Jonel', 'Mark Joseph',
        'John Michael', 'Rodel', 'Rommel', 'Alvin', 'Noel', 'Dexter', 'Ariel', 'Jeffrey', 'Randy',
        'Elmer', 'Rogelio', 'Lemuel', 'Earl John', 'Kurt', 'Clarence', 'Mico', 'Rhenz', 'Zaldy',
    ];

    private const SURNAMES = [
        'Dela Cruz', 'Garcia', 'Reyes', 'Ramos', 'Mendoza', 'Santos', 'Flores', 'Gonzales', 'Bautista',
        'Villanueva', 'Fernandez', 'Cruz', 'De Guzman', 'Lopez', 'Perez', 'Castillo', 'Francisco',
        'Rivera', 'Aquino', 'Castro', 'Sanchez', 'Torres', 'De Leon', 'Domingo', 'Martinez',
        'Rodriguez', 'Santiago', 'Soriano', 'Delos Santos', 'Diaz', 'Hernandez', 'Tolentino', 'Valdez',
        'Ramirez', 'Morales', 'Mercado', 'Tan', 'Aguilar', 'Navarro', 'Manalo', 'Gomez', 'Dizon',
        'Del Rosario', 'Javier', 'Corpuz', 'Gutierrez', 'Salvador', 'Velasco', 'Miranda', 'David',
        'Pascual', 'Lim', 'Go', 'Uy', 'Sy', 'Chua', 'Abellana', 'Bacalso', 'Cabahug', 'Pacaña', 'Tupas',
        'Montejo', 'Cañete', 'Ygot', 'Obando', 'Villareal', 'Evangelista', 'Padilla', 'Ocampo',
        'Salazar', 'Vergara', 'Robles', 'Estrada', 'Ferrer', 'Medina', 'Rosales', 'Sarmiento', 'Galang',
        'Lacson', 'Yap', 'Alcantara', 'Andrade', 'Arellano', 'Bernardo', 'Buenaventura', 'Cabrera',
        'Caballero', 'Espinosa', 'Fajardo', 'Gallardo', 'Ignacio', 'Jimenez', 'Legaspi', 'Lucero',
        'Magbanua', 'Nepomuceno', 'Pineda', 'Quiambao', 'Rosario', 'Saavedra', 'Umali', 'Vasquez',
        'Zamora', 'Abad', 'Advincula', 'Bonifacio', 'Cortez', 'Enriquez', 'Guevarra', 'Hidalgo',
        'Luna', 'Madrigal', 'Natividad', 'Panganiban', 'Regalado', 'Tiu', 'Villegas', 'Arcilla',
        'Pedrosa', 'Cinco', 'Gerona', 'Tabada', 'Ompad', 'Diola', 'Paredes', 'Cuizon', 'Largo',
        'Borromeo', 'Ybañez', 'Pepito', 'Lao', 'Durano', 'Obligado', 'Sumalinog', 'Cabusas', 'Remandaban',
    ];

    /** @var array<string, true> */
    private array $usedEmails = [];

    public function __construct(private readonly string $domain)
    {
    }

    /**
     * A new, randomly drawn person.
     *
     * @return array{name: string, first_name: string, middle_name: string, last_name: string, email: string}
     */
    public function next(): array
    {
        $firstNames = mt_rand(0, 1) === 0 ? self::FEMALE : self::MALE;
        $first = $firstNames[mt_rand(0, count($firstNames) - 1)];
        $last = self::SURNAMES[mt_rand(0, count(self::SURNAMES) - 1)];

        // The middle name is the mother's maiden surname, so never the same
        // as the family name.
        do {
            $middle = self::SURNAMES[mt_rand(0, count(self::SURNAMES) - 1)];
        } while ($middle === $last);

        return $this->person($first, $middle, $last);
    }

    /**
     * A specific person, for accounts the demo walks through by name --
     * optionally at a fixed address instead of one built from the name.
     *
     * @return array{name: string, first_name: string, middle_name: string, last_name: string, email: string}
     */
    public function person(string $first, string $middle, string $last, ?string $email = null): array
    {
        return [
            'name' => "{$first} {$middle} {$last}",
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'email' => $email ?? $this->uniqueEmail($first, $last),
        ];
    }

    private function uniqueEmail(string $first, string $last): string
    {
        $local = $this->slug($first) . '.' . $this->slug($last);
        $candidate = $local;

        for ($n = 2; isset($this->usedEmails[$candidate]); $n++) {
            $candidate = $local . $n;
        }

        $this->usedEmails[$candidate] = true;

        return $candidate . '@' . $this->domain;
    }

    private function slug(string $name): string
    {
        // "Ma. Lourdes" -> "malourdes", "Dela Cruz" -> "delacruz", "Cañete" -> "canete".
        return preg_replace('/[^a-z]/', '', strtolower(Str::ascii($name))) ?? '';
    }
}
