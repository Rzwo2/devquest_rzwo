<?php

namespace Mbx\DevquestSite\Command;

use Mbx\DevquestSite\Domain\Model\Immo;
use Mbx\DevquestSite\Domain\Repository\ImmoRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

#[AsCommand(name: 'mbx:immoImport')]
class ImmoImportCommand extends Command
{
    private const OPEN_PLZ_STREETS_URL = 'https://openplzapi.org/de/Streets';

    /** @var array<int, array{row: int, error: string, data: string|null}> */
    private array $errors = [];

    /** @var array<int, array{row: int, raw-data: string, corrected-data: string}> */
    private array $imported = [];

    public function __construct(
        private readonly ImmoRepository $immoRepository,
        private readonly PersistenceManagerInterface $persistenceManager,
        private readonly RequestFactory $requestFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Import immo data');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title($this->getDescription());

        $csvFile = new \SplFileObject('immo-import/immo_sample.csv', 'r');
        if ($csvFile->eof()) {
            return Command::INVALID;
        }

        $existingImmos = $this->getExistingImmos();

        $csvFile->fgetcsv(separator: ';'); // headline

        while (!$csvFile->eof()) {
            $csvData = $csvFile->fgetcsv(separator: ';');
            $row = $csvFile->key();
            $immo = $this->validateData($csvData, $row);
            if (null === $immo) {
                continue;
            }

            $key = $this->getKey($immo);

            if (isset($existingImmos[$key])) {
                continue;
            }

            $existingImmos[$key] = $immo;

            $this->persistenceManager->add($immo);

            $this->imported[] = ['row' => $row, 'raw-data' => implode('; ', $csvData), 'corrected-data' => $immo->__toString()];
        }

        $this->persistenceManager->persistAll();

        $this->displayResults($io);

        return 0;
    }

    /** @return aray<string, Immo> */
    private function getExistingImmos(): array
    {
        /** @var Immo[] */
        $immos = $this->immoRepository->findAll();

        $existing = [];
        foreach ($immos as $immo) {
            $key = $this->getKey($immo);
            $existing[$key] = $immo;
        }

        return $existing;
    }

    private function getKey(Immo $immo): string
    {
        return sprintf(
            '%s|%s|%s|%s|%s|%s|%s',
            $immo->getStreet(),
            $immo->getPostalCode(),
            $immo->getCity(),
            $immo->getColdRent(),
            $immo->getWarmRent(),
            $immo->getArea(),
            $immo->getRooms(),
        );
    }

    /** @param string[] $csvData */
    private function validateData(array $csvData, int $lineNumber): ?Immo
    {
        if (count($csvData) <= 1) {
            return null;
        }

        $street = trim($csvData[0]);
        $postalCode = trim($csvData[1]);
        $city = trim($csvData[2]);
        $coldRent = $this->parseDecimal($csvData[3]);
        $warmRent = $this->parseDecimal($csvData[4]);
        $area = $this->parseDecimal($csvData[5]);
        $rooms = $this->parseInt($csvData[6]);

        $errors = [];

        if (empty($street)) {
            $errors[] = 'Street is empty';
        }

        $postalCode = preg_replace('/^D-/', '', $postalCode);
        if (!preg_match('/^\d{5}$/', $postalCode)) {
            $errors[] = "Invalid postal code: '{$csvData[1]}'";
        }

        if (empty($city)) {
            $errors[] = 'City is empty';
        }

        if (!empty($street) && !empty($city) && preg_match('/^\d{5}$/', $postalCode)) {
            $error = $this->validateAddress($street, $city, $postalCode);
            if ($error) {
                $errors[] = $error;
            }
        }

        if (null === $coldRent) {
            $errors[] = "Invalid cold rent: '{$csvData[3]}'";
        } elseif ($coldRent <= 0) {
            $errors[] = "Cold rent must be positive: {$coldRent}";
        }

        if (null === $warmRent) {
            $errors[] = "Invalid warm rent: '{$csvData[4]}'";
        } elseif ($warmRent <= 0) {
            $errors[] = "Warm rent must be positive: {$warmRent}";
        }

        if (null === $area) {
            $errors[] = "Invalid area: '{$csvData[5]}'";
        } elseif ($area <= 0) {
            $errors[] = "Area must be positive: {$area}";
        }

        if (null === $rooms) {
            $errors[] = "Invalid rooms: '{$csvData[6]}'";
        } elseif ($rooms <= 0) {
            $errors[] = "Rooms must be positive: {$rooms}";
        }

        if (!empty($errors)) {
            $this->errors[] = [
                'row' => $lineNumber,
                'error' => implode('; ', $errors),
                'data' => implode('; ', $csvData),
            ];

            return null;
        }

        return (new Immo())
            ->setStreet($street)
            ->setPostalCode($postalCode)
            ->setCity($city)
            ->setColdRent($coldRent)
            ->setWarmRent($warmRent)
            ->setArea($area)
            ->setRooms($rooms);
    }

    private function parseDecimal(string $value): ?float
    {
        $value = trim($value);

        if (empty($value)) {
            return null;
        }

        $value = str_replace(',', '.', $value);

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function parseInt(string $value): ?int
    {
        $value = trim($value);

        return ctype_digit($value) ? (int) $value : null;
    }

    private function validateAddress(string &$street, string &$city, string &$postalCode): string
    {
        $pos = strrpos($street, ' ') ?: strlen($street);
        $parts = str_split($street, $pos);
        $streetSearchPattern = '^' . $parts[0];
        $streetNumber = $parts[1] ?? '';

        if (false !== stripos($streetSearchPattern, 'straße') || false !== stripos($streetSearchPattern, 'strasse')) {
            $streetSearchPattern = str_ireplace(['straße', 'strasse'], 'str', $streetSearchPattern);
        } else {
            $streetSearchPattern .= '$';
        }

        /** FULL SEARCH */
        $url = HttpUtility::buildUrl([
            'path' => self::OPEN_PLZ_STREETS_URL,
            'query' => HttpUtility::buildQueryString([
                'name' => $streetSearchPattern,
                'postalCode' => $postalCode,
                'locality' => "^$city$",
            ]),
        ]);
        $response = $this->requestFactory->request($url);
        $content = json_decode($response->getBody()->getContents());
        if ($content) {
            if (count($content) > 1) {
                return 'Address ambiguous';
            }
            $street = $content[0]->name . $streetNumber;
            $city = $content[0]->locality;
            $postalCode = $content[0]->postalCode;

            return '';
        }

        /** Without City */
        $url = HttpUtility::buildUrl([
            'path' => self::OPEN_PLZ_STREETS_URL,
            'query' => HttpUtility::buildQueryString([
                'name' => $streetSearchPattern,
                'postalCode' => $postalCode,
            ]),
        ]);
        $content = json_decode($this->requestFactory->request($url)->getBody()->getContents());
        if ($content) {
            if (count($content) > 1) {
                return 'Address city wrong and ambiguous findings for street + postal code';
            }
            $street = $content[0]->name . $streetNumber;
            $city = $content[0]->locality;
            $postalCode = $content[0]->postalCode;

            return '';
        }

        /** Without postal code */
        $url = HttpUtility::buildUrl([
            'path' => self::OPEN_PLZ_STREETS_URL,
            'query' => HttpUtility::buildQueryString([
                'name' => $streetSearchPattern,
                'locality' => "^$city$",
            ]),
        ]);
        $content = json_decode($this->requestFactory->request($url)->getBody()->getContents());

        if ($content) {
            if (count($content) > 1) {
                return 'Address postal-code wrong and ambiguous findings for street + city';
            }
            $street = $content[0]->name . $streetNumber;
            $city = $content[0]->locality;
            $postalCode = $content[0]->postalCode;

            return '';
        }

        return 'Invalid address.';
    }

    private function displayResults(SymfonyStyle $io): void
    {
        $io->section('Import Summary');
        $io->table(
            ['Category', 'Count'],
            [
                ['Valid records', count($this->imported)],
                ['Errors', count($this->errors)],
            ]
        );

        if (count($this->imported) > 0) {
            $io->section('Valid Records');
            foreach ($this->imported as $record) {
                $row = str_pad($record['row'], 2, ' ');
                $io->text("Row $row\n raw-data: {$record['raw-data']}\n new-data: {$record['corrected-data']}\n");
            }
        }

        if (count($this->errors) > 0) {
            $io->section('Errors');
            $errors = array_map(static fn (array $error) => "Row {$error['row']}: {$error['error']}\n  → {$error['data']}\n", $this->errors);
            $io->text($errors);
        }
    }
}
