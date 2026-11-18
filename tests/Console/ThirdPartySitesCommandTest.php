<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Console;

use Quire\Server\Console\ThirdPartySitesCommand;
use Quire\Server\Git\Repository;
use Quire\Server\Registry\SiteGit;
use Quire\Server\Registry\SiteRegistry;
use Quire\Server\Site\Domain;
use Quire\Server\Site\ServerPaths;
use Quire\Server\Site\Site;
use Quire\Server\Site\SiteName;
use Quire\Server\Tests\Registry\RegistryTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * What the updater asks the agent before undoing an update to 0.6: the managed sites with third-party
 * packages (it doesn't mount sites' folders).
 */
final class ThirdPartySitesCommandTest extends RegistryTestCase
{
    private string $root;

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
        parent::tearDown();
    }

    public function testTheSitesAndTheirThirdPartyPackages(): void
    {
        $this->root = sys_get_temp_dir().'/quire-third-party-'.bin2hex(random_bytes(6));
        $paths = new ServerPaths($this->root);
        $registry = new SiteRegistry($this->connection, $this->schema);
        $repositories = new SiteGit($this->connection, $this->schema);
        $record = static fn (array $packages): string => (string) json_encode(['require' => [], 'extra' => ['quire-server' => ['packages' => $packages]]]);

        foreach ([
            'jane' => $record(['acme/reviews' => ['third-party' => true], 'quire/analytics' => ['third-party' => false]]),
            'kim' => $record(['quire/analytics' => ['third-party' => false]]),
            'blog' => $record(['acme/reviews' => ['third-party' => true]]),
            'lee' => null,
        ] as $site => $composerJson) {
            $registry->add(new SiteName($site), [new Domain($site.'.example')], Site::SHARED_DATABASE, $site);

            if (null !== $composerJson) {
                new Filesystem()->dumpFile($paths->site(new SiteName($site)).'/composer.json', $composerJson);
            }
        }

        // A Git mode site's packages are its repository's: it gets no updates from the server anyway.
        $repositories->follow(new SiteName('blog'), new Repository('https://github.com/jane/blog.git', 'main'), false);

        $application = new Application();
        $application->addCommand(new ThirdPartySitesCommand($registry, $repositories, $paths));
        $tester = new CommandTester($application->find(ThirdPartySitesCommand::NAME));
        $tester->execute([]);

        self::assertSame(['jane' => ['acme/reviews']], json_decode($tester->getDisplay(), true));
    }
}
