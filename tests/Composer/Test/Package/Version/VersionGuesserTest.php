<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Package\Version;

use Composer\Config;
use Composer\Package\Version\VersionGuesser;
use Composer\Semver\VersionParser;
use Composer\Test\TestCase;
use Composer\Util\Git as GitUtil;
use Composer\Util\ProcessExecutor;

class VersionGuesserTest extends TestCase
{
    public function setUp()
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is not available');
        }
    }

    public function testHgGuessVersionReturnsData()
    {
        $branch = 'default';

        $executor = $this->getMockBuilder('Composer\\Util\\ProcessExecutor')
            ->setMethods(array('execute'))
            ->disableArgumentCloning()
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $self = $this;
        $step = 0;

        GitUtil::getVersion(new ProcessExecutor);

        $executor
            ->expects($this->at($step))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self) {
                $self->assertEquals('git branch -a --no-color --no-abbrev -v', $command);

                return 128;
            })
        ;

        ++$step;
        $executor
            ->expects($this->at($step))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self) {
                $self->assertEquals('git describe --exact-match --tags', $command);

                return 128;
            })
        ;

        ++$step;
        $executor
            ->expects($this->at($step))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self, $executor) {
                $self->assertEquals('git log --pretty="%H" -n1 HEAD'.GitUtil::getNoShowSignatureFlag($executor), $command);

                return 128;
            })
        ;

        ++$step;
        $executor
            ->expects($this->at($step))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self, $branch) {
                $self->assertEquals('hg branch', $command);
                $output = $branch;

                return 0;
            })
        ;

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $executor, new VersionParser());
        $versionArray = $guesser->guessVersion(array(), 'dummy/path');

        $this->assertEquals("9999999-dev", $versionArray['version']);
        $this->assertEquals("dev-".$branch, $versionArray['pretty_version']);
        $this->assertEmpty($versionArray['commit']);
    }

    public function testGuessVersionReturnsData()
    {
        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';
        $anotherCommitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';

        $executor = $this->getMockBuilder('\\Composer\\Util\\ProcessExecutor')
            ->setMethods(array('execute'))
            ->disableArgumentCloning()
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $self = $this;

        $executor
            ->expects($this->at(0))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self, $commitHash, $anotherCommitHash) {
                $self->assertEquals('git branch -a --no-color --no-abbrev -v', $command);
                $output = "* master $commitHash Commit message\n(no branch) $anotherCommitHash Commit message\n";

                return 0;
            })
        ;

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $executor, new VersionParser());
        $versionArray = $guesser->guessVersion(array(), 'dummy/path');

        $this->assertEquals("9999999-dev", $versionArray['version']);
        $this->assertEquals("dev-master", $versionArray['pretty_version']);
        $this->assertArrayNotHasKey('feature_version', $versionArray);
        $this->assertArrayNotHasKey('feature_pretty_version', $versionArray);
        $this->assertEquals($commitHash, $versionArray['commit']);
    }

    public function testGuessVersionReadsAndRespectsNonFeatureBranchesConfigurationForArbitraryNaming()
    {
        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';
        $anotherCommitHash = '13a15d220da53c52eddd5f32ffca64a7b3801bea';

        $executor = $this->getMockBuilder('\\Composer\\Util\\ProcessExecutor')
            ->setMethods(array('execute'))
            ->disableArgumentCloning()
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $self = $this;

        $executor
            ->expects($this->at(0))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self, $commitHash, $anotherCommitHash) {
                $self->assertEquals('git branch -a --no-color --no-abbrev -v', $command);
                $output = "  arbitrary $commitHash Commit message\n* current $anotherCommitHash Another message\n";

                return 0;
            })
        ;

        $executor
            ->expects($this->at(1))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output, $path) use ($self, $anotherCommitHash) {
                $self->assertEquals('git rev-list arbitrary..current', $command);
                $output = "$anotherCommitHash\n";

                return 0;
            })
        ;

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $executor, new VersionParser());
        $versionArray = $guesser->guessVersion(array('version' => 'self.version', 'non-feature-branches' => array('arbitrary')), 'dummy/path');

        $this->assertEquals("dev-arbitrary", $versionArray['version']);
        $this->assertEquals($anotherCommitHash, $versionArray['commit']);
        $this->assertEquals("dev-current", $versionArray['feature_version']);
        $this->assertEquals("dev-current", $versionArray['feature_pretty_version']);
    }

    public function testGuessVersionReadsAndRespectsNonFeatureBranchesConfigurationForArbitraryNamingRegex()
    {
        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';
        $anotherCommitHash = '13a15d220da53c52eddd5f32ffca64a7b3801bea';

        $executor = $this->getMockBuilder('\\Composer\\Util\\ProcessExecutor')
            ->setMethods(array('execute'))
            ->disableArgumentCloning()
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $self = $this;

        $executor
            ->expects($this->at(0))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self, $commitHash, $anotherCommitHash) {
                $self->assertEquals('git branch -a --no-color --no-abbrev -v', $command);
                $output = "  latest-testing $commitHash Commit message\n* current $anotherCommitHash Another message\n";

                return 0;
            })
        ;

        $executor
            ->expects($this->at(1))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output, $path) use ($self, $anotherCommitHash) {
                $self->assertEquals('git rev-list latest-testing..current', $command);
                $output = "$anotherCommitHash\n";

                return 0;
            })
        ;

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $executor, new VersionParser());
        $versionArray = $guesser->guessVersion(array('version' => 'self.version', 'non-feature-branches' => array('latest-.*')), 'dummy/path');

        $this->assertEquals("dev-latest-testing", $versionArray['version']);
        $this->assertEquals($anotherCommitHash, $versionArray['commit']);
        $this->assertEquals("dev-current", $versionArray['feature_version']);
        $this->assertEquals("dev-current", $versionArray['feature_pretty_version']);
    }

    public function testGuessVersionReadsAndRespectsNonFeatureBranchesConfigurationForArbitraryNamingWhenOnNonFeatureBranch()
    {
        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';
        $anotherCommitHash = '13a15d220da53c52eddd5f32ffca64a7b3801bea';

        $executor = $this->getMockBuilder('\\Composer\\Util\\ProcessExecutor')
            ->setMethods(array('execute'))
            ->disableArgumentCloning()
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $self = $this;

        $executor
            ->expects($this->at(0))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self, $commitHash, $anotherCommitHash) {
                $self->assertEquals('git branch -a --no-color --no-abbrev -v', $command);
                $output = "* latest-testing $commitHash Commit message\n  current $anotherCommitHash Another message\n  master $anotherCommitHash Another message\n";

                return 0;
            })
        ;

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $executor, new VersionParser());
        $versionArray = $guesser->guessVersion(array('version' => 'self.version', 'non-feature-branches' => array('latest-.*')), 'dummy/path');

        $this->assertEquals("dev-latest-testing", $versionArray['version']);
        $this->assertEquals($commitHash, $versionArray['commit']);
        $this->assertArrayNotHasKey('feature_version', $versionArray);
        $this->assertArrayNotHasKey('feature_pretty_version', $versionArray);
    }

    public function testDetachedHeadBecomesDevHash()
    {
        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';

        $executor = $this->getMockBuilder('\\Composer\\Util\\ProcessExecutor')
            ->setMethods(array('execute'))
            ->disableArgumentCloning()
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $self = $this;

        $executor
            ->expects($this->at(0))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self, $commitHash) {
                $self->assertEquals('git branch -a --no-color --no-abbrev -v', $command);
                $output = "* (no branch) $commitHash Commit message\n";

                return 0;
            })
        ;

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $executor, new VersionParser());
        $versionData = $guesser->guessVersion(array(), 'dummy/path');

        $this->assertEquals("dev-$commitHash", $versionData['version']);
    }

    public function testDetachedFetchHeadBecomesDevHashGit2()
    {
        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';

        $executor = $this->getMockBuilder('\\Composer\\Util\\ProcessExecutor')
            ->setMethods(array('execute'))
            ->disableArgumentCloning()
            ->disableOriginalConstructor()
            ->getMock();

        $self = $this;

        $executor
            ->expects($this->at(0))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self, $commitHash) {
                $self->assertEquals('git branch -a --no-color --no-abbrev -v', $command);
                $output = "* (HEAD detached at FETCH_HEAD) $commitHash Commit message\n";

                return 0;
            });

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $executor, new VersionParser());
        $versionData = $guesser->guessVersion(array(), 'dummy/path');

        $this->assertEquals("dev-$commitHash", $versionData['version']);
    }

    public function testDetachedCommitHeadBecomesDevHashGit2()
    {
        $commitHash = '03a15d220da53c52eddd5f32ffca64a7b3801bea';

        $executor = $this->getMockBuilder('\\Composer\\Util\\ProcessExecutor')
            ->setMethods(array('execute'))
            ->disableArgumentCloning()
            ->disableOriginalConstructor()
            ->getMock();

        $self = $this;

        $executor
            ->expects($this->at(0))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self, $commitHash) {
                $self->assertEquals('git branch -a --no-color --no-abbrev -v', $command);
                $output = "* (HEAD detached at 03a15d220) $commitHash Commit message\n";

                return 0;
            });

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $executor, new VersionParser());
        $versionData = $guesser->guessVersion(array(), 'dummy/path');

        $this->assertEquals("dev-$commitHash", $versionData['version']);
    }

    public function testTagBecomesVersion()
    {
        $executor = $this->getMockBuilder('\\Composer\\Util\\ProcessExecutor')
            ->setMethods(array('execute'))
            ->disableArgumentCloning()
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $self = $this;

        $executor
            ->expects($this->at(0))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self) {
                $self->assertEquals('git branch -a --no-color --no-abbrev -v', $command);
                $output = "* (HEAD detached at v2.0.5-alpha2) 433b98d4218c181bae01865901aac045585e8a1a Commit message\n";

                return 0;
            })
        ;

        $executor
            ->expects($this->at(1))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self) {
                $self->assertEquals('git describe --exact-match --tags', $command);
                $output = "v2.0.5-alpha2";

                return 0;
            })
        ;

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $executor, new VersionParser());
        $versionData = $guesser->guessVersion(array(), 'dummy/path');

        $this->assertEquals("2.0.5.0-alpha2", $versionData['version']);
    }

    public function testTagBecomesPrettyVersion()
    {
        $executor = $this->getMockBuilder('\\Composer\\Util\\ProcessExecutor')
            ->setMethods(array('execute'))
            ->disableArgumentCloning()
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $self = $this;

        $executor
            ->expects($this->at(0))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self) {
                $self->assertEquals('git branch -a --no-color --no-abbrev -v', $command);
                $output = "* (HEAD detached at 1.0.0) c006f0c12bbbf197b5c071ffb1c0e9812bb14a4d Commit message\n";

                return 0;
            })
        ;

        $executor
            ->expects($this->at(1))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self) {
                $self->assertEquals('git describe --exact-match --tags', $command);
                $output = '1.0.0';

                return 0;
            })
        ;

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $executor, new VersionParser());
        $versionData = $guesser->guessVersion(array(), 'dummy/path');

        $this->assertEquals('1.0.0.0', $versionData['version']);
        $this->assertEquals('1.0.0', $versionData['pretty_version']);
    }

    public function testInvalidTagBecomesVersion()
    {
        $executor = $this->getMockBuilder('\\Composer\\Util\\ProcessExecutor')
            ->setMethods(array('execute'))
            ->disableArgumentCloning()
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $self = $this;

        $executor
            ->expects($this->at(0))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self) {
                $self->assertEquals('git branch -a --no-color --no-abbrev -v', $command);
                $output = "* foo 03a15d220da53c52eddd5f32ffca64a7b3801bea Commit message\n";

                return 0;
            })
        ;

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $executor, new VersionParser());
        $versionData = $guesser->guessVersion(array(), 'dummy/path');

        $this->assertEquals("dev-foo", $versionData['version']);
    }

    public function testNumericBranchesShowNicely()
    {
        $executor = $this->getMockBuilder('\\Composer\\Util\\ProcessExecutor')
            ->setMethods(array('execute'))
            ->disableArgumentCloning()
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $self = $this;

        $executor
            ->expects($this->at(0))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self) {
                $self->assertEquals('git branch -a --no-color --no-abbrev -v', $command);
                $output = "* 1.5 03a15d220da53c52eddd5f32ffca64a7b3801bea Commit message\n";

                return 0;
            })
        ;

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $executor, new VersionParser());
        $versionData = $guesser->guessVersion(array(), 'dummy/path');

        $this->assertEquals("1.5.x-dev", $versionData['pretty_version']);
        $this->assertEquals("1.5.9999999.9999999-dev", $versionData['version']);
    }

    public function testRemoteBranchesAreSelected()
    {
        $executor = $this->getMockBuilder('\\Composer\\Util\\ProcessExecutor')
            ->setMethods(array('execute'))
            ->disableArgumentCloning()
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $self = $this;

        $executor
            ->expects($this->at(0))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output) use ($self) {
                $self->assertEquals('git branch -a --no-color --no-abbrev -v', $command);
                $output = "* feature-branch 03a15d220da53c52eddd5f32ffca64a7b3801bea Commit message\n".
                    "remotes/origin/1.5 03a15d220da53c52eddd5f32ffca64a7b3801bea Commit message\n";

                return 0;
            })
        ;

        $executor
            ->expects($this->at(1))
            ->method('execute')
            ->willReturnCallback(function ($command, &$output, $path) use ($self) {
                $self->assertEquals('git rev-list remotes/origin/1.5..feature-branch', $command);
                $output = "\n";

                return 0;
            })
        ;

        $config = new Config;
        $config->merge(array('repositories' => array('packagist' => false)));
        $guesser = new VersionGuesser($config, $executor, new VersionParser());
        $versionData = $guesser->guessVersion(array('version' => 'self.version'), 'dummy/path');
        $this->assertEquals("1.5.x-dev", $versionData['pretty_version']);
        $this->assertEquals("1.5.9999999.9999999-dev", $versionData['version']);
    }
}
