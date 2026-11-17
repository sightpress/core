<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Site\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Database\DatabaseDsn;
use SightPress\Core\Site\Command\DatabaseQuestions;
use SightPress\Core\Tests\Support\ArrayCommandInput;
use SightPress\Module\Console\CommandParameter;

#[CoversClass(DatabaseQuestions::class)]
#[CoversClass(DatabaseDsn::class)]
final class DatabaseQuestionsTest extends TestCase
{
    public function testTheWholeAddressWins(): void
    {
        self::assertSame('sqlite:////x.db', DatabaseQuestions::dsn(new ArrayCommandInput(options: ['db' => ' sqlite:////x.db ', 'db-engine' => 'mysql'])));
    }

    public function testBuildsAnAddressFromThePiecesWithTheCredentialsEncoded(): void
    {
        $dsn = DatabaseQuestions::dsn(new ArrayCommandInput(options: [
            'db-engine' => 'mariadb', 'db-host' => 'db', 'db-port' => '3306', 'db-name' => 'demo', 'db-user' => 'ada lovelace', 'db-password' => 'p@ss:/word',
        ]));

        self::assertSame('mariadb://ada%20lovelace:p%40ss%3A%2Fword@db:3306/demo', $dsn);
        $params = DatabaseDsn::parse($dsn)->connectionParams();
        self::assertSame('ada lovelace', $params['user'] ?? null);
        self::assertSame('p@ss:/word', $params['password'] ?? null);
        self::assertSame('postgresql://sightpress@db:5432/demo', DatabaseQuestions::dsn(new ArrayCommandInput(options: [
            'db-engine' => 'postgresql', 'db-host' => 'db', 'db-port' => '5432', 'db-name' => 'demo', 'db-user' => 'sightpress',
        ])));
        self::assertSame('sqlite:////srv/demo.sqlite', DatabaseQuestions::dsn(new ArrayCommandInput(options: ['db-path' => '/srv/demo.sqlite'])));
    }

    public function testDefaultsFollowTheEngineAndTheSlug(): void
    {
        $parameters = [];
        foreach (new DatabaseQuestions('/srv/sites')->parameters(static fn (): string => 'my-site') as $parameter) {
            $parameters[$parameter->name] = $parameter;
        }
        $answers = new ArrayCommandInput(options: ['db-engine' => 'mysql']);

        self::assertSame('/srv/sites/my-site.sqlite', self::default($parameters['db-path'], $answers));
        self::assertSame('3306', self::default($parameters['db-port'], $answers));
        self::assertNull(self::default($parameters['db-port'], new ArrayCommandInput(options: ['db-engine' => 'oracle'])));
        self::assertSame('sightpress_my_site', self::default($parameters['db-name'], $answers));
        self::assertSame('/srv/sites/a/b.sqlite', ($parameters['db-path']->normalise ?? self::fail())('a/b.sqlite', $answers));
        self::assertSame('/x.sqlite', ($parameters['db-path']->normalise ?? self::fail())('/x.sqlite', $answers));
        self::assertSame('10.0.0.5', ($parameters['db-host']->normalise ?? self::fail())('10.0.0.5', $answers));
        self::assertSame('db.internal', ($parameters['db-host']->normalise ?? self::fail())('DB.internal', $answers));
        self::assertSame('5432', ($parameters['db-port']->normalise ?? self::fail())('5432', $answers));
        self::assertSame('sightpress_demo', ($parameters['db-name']->normalise ?? self::fail())('sightpress_demo', $answers));
    }

    public function testExplainsWhatIsWrongWithAPiece(): void
    {
        $parameters = [];
        foreach (new DatabaseQuestions('/srv/sites')->parameters(static fn (): string => 'demo') as $parameter) {
            $parameters[$parameter->name] = $parameter->normalise;
        }
        $answers = new ArrayCommandInput();
        $wrong = [
            ['db-path', 'folder/', 'Give the path of a file, not of a folder, for example /srv/sites/demo.sqlite.'],
            ['db-host', 'db server', '"db server" is not a server name or address: use one such as localhost, db or 10.0.0.5.'],
            ['db-port', '0', '"0" is not a port: use a whole number from 1 to 65535, such as 5432.'],
            ['db-name', 'demo;drop', '"demo;drop" is not a database name: use letters, digits and _, such as sightpress_demo.'],
        ];
        foreach ($wrong as [$name, $value, $message]) {
            try {
                ($parameters[$name] ?? self::fail())($value, $answers);
                self::fail('accepted ' . $value);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    private static function default(CommandParameter $parameter, ArrayCommandInput $answers): ?string
    {
        return $parameter->default instanceof \Closure ? ($parameter->default)($answers) : $parameter->default;
    }
}
