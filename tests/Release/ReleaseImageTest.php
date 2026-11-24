<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Release;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Where a release's image comes from (.github/release-image.sh): a final release with release
 * candidates ships its highest candidate's image, the one that was tested, and only from that
 * candidate's commit and once its release and image check out for its own tag. With a real Git
 * repository, and gh and cosign scripted.
 */
final class ReleaseImageTest extends TestCase
{
    private const string IMAGE = 'ghcr.io/quirecms/server';
    private const string DIGEST = 'sha256:4a4a4a4a4a4a4a4a4a4a4a4a4a4a4a4a4a4a4a4a4a4a4a4a4a4a4a4a4a4a4a4a';

    private string $root;

    /** @var array<string, string> What the scripted gh serves: release.env by tag; a tag without one isn't published */
    private array $published = [];

    /** @var list<string> The tags whose release.env's signature checks out */
    private array $blobSignedFor = [];

    /** @var list<string> The tags whose image's signature checks out */
    private array $imageSignedFor = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quire-release-image-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir([$this->root.'/repository', $this->root.'/bin']);
        $this->git('init', '-q', '-b', 'main');
        $this->git('-c', 'user.name=t', '-c', 'user.email=t@example.com', 'commit', '-q', '--allow-empty', '-m', 'one');
        $this->git('-c', 'user.name=t', '-c', 'user.email=t@example.com', 'commit', '-q', '--allow-empty', '-m', 'two');
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testAReleaseWithoutCandidatesIsBuilt(): void
    {
        $this->tag('v0.6.1', 'HEAD~1');

        self::assertSame("mode=build\n", $this->run_('0.7.0')->getOutput());
    }

    public function testACandidateIsBuilt(): void
    {
        $this->tag('v0.7.0-rc.1', 'HEAD');

        self::assertSame("mode=build\n", $this->run_('0.7.0-rc.2')->getOutput(), 'Each candidate is built: it\'s what gets tested.');
    }

    public function testAFinalOnItsHighestCandidatesCommitShipsThatCandidatesImage(): void
    {
        $this->tag('v0.7.0-rc.1', 'HEAD~1');
        $this->tag('v0.7.0-rc.2', 'HEAD');
        $this->candidate('v0.7.0-rc.2');

        $run = $this->run_('0.7.0');

        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        self::assertSame("mode=promote\ndigest=".self::DIGEST."\ncandidate=v0.7.0-rc.2\n", $run->getOutput());
    }

    public function testTheHighestIsCountedAsANumber(): void
    {
        $this->tag('v0.7.0-rc.9', 'HEAD~1');
        $this->tag('v0.7.0-rc.10', 'HEAD');
        $this->candidate('v0.7.0-rc.10');

        self::assertStringContainsString('candidate=v0.7.0-rc.10', $this->run_('0.7.0')->getOutput());
    }

    public function testAFinalOnAnotherCommitIsRefused(): void
    {
        // As 0.6.0 was: the candidate on one commit, the final on the next (which dated the changelog)
        $this->tag('v0.7.0-rc.1', 'HEAD~1');
        $this->candidate('v0.7.0-rc.1');

        $run = $this->run_('0.7.0');

        self::assertSame(1, $run->getExitCode());
        self::assertStringContainsString('its highest release candidate, v0.7.0-rc.1, is on '.$this->commit('HEAD~1'), $run->getErrorOutput());
        self::assertStringContainsString('Tag a new release candidate on this commit', $run->getErrorOutput());
        self::assertSame('', $run->getOutput(), 'Nothing for the workflow to go on with.');
    }

    public function testAFinalOnALowerCandidatesCommitIsRefused(): void
    {
        // rc.2 superseded rc.1: rc.1's image isn't the one last tested
        $this->tag('v0.7.0-rc.1', 'HEAD');
        $this->tag('v0.7.0-rc.2', 'HEAD~1');
        $this->candidate('v0.7.0-rc.1');

        self::assertStringContainsString('its highest release candidate, v0.7.0-rc.2', $this->refused());
    }

    public function testACandidateWhoseReleaseIsntPublishedIsRefused(): void
    {
        $this->tag('v0.7.0-rc.1', 'HEAD');

        self::assertStringContainsString('v0.7.0-rc.1\'s release (its release.env and signature) can\'t be got', $this->refused());
    }

    public function testACandidatesReleaseNotSignedForItsOwnTagIsRefused(): void
    {
        $this->tag('v0.7.0-rc.1', 'HEAD');
        $this->candidate('v0.7.0-rc.1');
        // Signed, but for another tag (a release.env copied from one release to another)
        $this->blobSignedFor = ['v0.6.1'];

        self::assertStringContainsString('release.env isn\'t signed by this repository\'s release workflow for v0.7.0-rc.1', $this->refused());
    }

    public function testACandidateNamingAnotherImageIsRefused(): void
    {
        $this->tag('v0.7.0-rc.1', 'HEAD');
        $this->candidate('v0.7.0-rc.1', 'ghcr.io/someone/server@'.self::DIGEST);

        self::assertStringContainsString('names "ghcr.io/someone/server@'.self::DIGEST.'" as its image, not one of '.self::IMAGE.' by digest', $this->refused());
    }

    public function testACandidatesImageNotSignedForItsTagOrGoneIsRefused(): void
    {
        $this->tag('v0.7.0-rc.1', 'HEAD');
        $this->candidate('v0.7.0-rc.1');
        $this->imageSignedFor = [];

        self::assertStringContainsString('v0.7.0-rc.1\'s image ('.self::IMAGE.'@'.self::DIGEST.') isn\'t signed', $this->refused());
    }

    public function testTheWorkflowShipsWhatTheScriptSays(): void
    {
        $workflow = (string) file_get_contents(\dirname(__DIR__, 2).'/.github/workflows/release.yaml');

        self::assertStringContainsString('.github/release-image.sh "$VERSION" "$GITHUB_SHA" "$IMAGE"', $workflow);
        self::assertStringContainsString("if: steps.source.outputs.mode == 'build'", $workflow, 'Built only when there\'s no candidate to promote.');
        self::assertMatchesRegularExpression('#aquasec/trivy:[0-9.]+@sha256:[0-9a-f]{64}#', $workflow, 'The scanner by digest.');
    }

    private function refused(): string
    {
        $run = $this->run_('0.7.0');
        self::assertSame(1, $run->getExitCode(), $run->getOutput());
        self::assertSame('', $run->getOutput());

        return $run->getErrorOutput();
    }

    /**
     * A candidate's release, published and signed for its tag, naming its image.
     */
    private function candidate(string $tag, string $image = self::IMAGE.'@'.self::DIGEST): void
    {
        $this->published[$tag] = 'VERSION='.ltrim($tag, 'v')."\nIMAGE=$image\nARCHIVE=quire-server.tar.gz\n";
        $this->blobSignedFor[] = $tag;
        $this->imageSignedFor[] = $tag;
    }

    private function run_(string $version): Process
    {
        $fs = new Filesystem();
        $fs->remove($this->root.'/served');

        foreach ($this->published as $tag => $env) {
            $fs->dumpFile($this->root.'/served/'.$tag.'/release.env', $env);
            $fs->dumpFile($this->root.'/served/'.$tag.'/release.env.sigstore.json', '{}');
        }

        $identity = 'https://github.com/quirecms/server/.github/workflows/release.yaml@refs/tags/';
        // gh release download <tag> … --dir <dir>: the tag's files, or a failure when it has none
        $fs->dumpFile($this->root.'/bin/gh', "#!/bin/sh\ntag=\$3\ndir=\$(printf '%s\\n' \"\$@\" | sed -n '/^--dir\$/{n;p;}')\n[ -d \"".$this->root."/served/\$tag\" ] || { echo 'release not found' >&2; exit 1; }\ncp \"".$this->root."/served/\$tag\"/* \"\$dir/\"\n");
        // cosign verify-blob|verify … --certificate-identity <identity> …: passes for the tags listed
        $fs->dumpFile($this->root.'/bin/cosign', "#!/bin/sh\nwhat=\$1\nidentity=\$(printf '%s\\n' \"\$@\" | sed -n '/^--certificate-identity\$/{n;p;}')\n"
            ."[ \"\$(printf '%s\\n' \"\$@\" | sed -n '/^--certificate-oidc-issuer\$/{n;p;}')\" = https://token.actions.githubusercontent.com ] || exit 1\n"
            ."case \$what in verify-blob) allowed='".implode(' ', $this->blobSignedFor)."';; verify) allowed='".implode(' ', $this->imageSignedFor)."';; *) exit 2;; esac\n"
            .'for tag in $allowed; do [ "$identity" = "'.$identity."\$tag\" ] && exit 0; done\nexit 1\n");
        chmod($this->root.'/bin/gh', 0o755);
        chmod($this->root.'/bin/cosign', 0o755);

        $run = new Process([\dirname(__DIR__, 2).'/.github/release-image.sh', $version, $this->commit('HEAD'), self::IMAGE], $this->root.'/repository', ['PATH' => $this->root.'/bin:'.getenv('PATH'), 'GITHUB_REPOSITORY' => 'quirecms/server']);
        $run->run();

        return $run;
    }

    private function tag(string $tag, string $at): void
    {
        $this->git('-c', 'user.name=t', '-c', 'user.email=t@example.com', 'tag', '-a', '-m', $tag, $tag, $at);
    }

    private function commit(string $ref): string
    {
        return trim($this->git('rev-parse', $ref));
    }

    private function git(string ...$arguments): string
    {
        $git = new Process(['git', ...$arguments], $this->root.'/repository');
        $git->mustRun();

        return $git->getOutput();
    }
}
