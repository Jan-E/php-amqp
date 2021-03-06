<?php
namespace phpamqp;

ini_set('assert.bail', true);

use DateTimeImmutable;
use DOMDocument;
use SimpleXMLElement;
use function dom_import_simplexml;
use function escapeshellarg;
use function escapeshellcmd;
use function exec;
use function file_get_contents;
use function file_put_contents;
use function ini_set;
use function preg_match;
use function realpath;
use function simplexml_import_dom;
use function strpos;
use function sys_get_temp_dir;
use function tempnam;
use function trim;

const BASE_DIR = __DIR__ . '/../';
const STABILITY_REGEX = '(?:alpha|beta|dev)\d*';
const MAJOR_MINOR_PATCH = '\d+\.\d+\.\d+';
const VERSION_REGEX = MAJOR_MINOR_PATCH . '(?:' . STABILITY_REGEX . ')?';
const VERSION_REGEX_DEV = MAJOR_MINOR_PATCH . 'dev';
const HEADER_VERSION_FILE = BASE_DIR . '/php_amqp.h';
const PACKAGE_XML = BASE_DIR . '/package.xml';
const ISSUE_URL_TEMPLATE = 'https://github.com/php-amqp/php-amqp/issues/%d';
const COMMIT_URL_TEMPLATE = 'https://github.com/php-amqp/php-amqp/issues/%d';
const COMMIT_MESSAGE_CHANGELOG_IGNORED = [
    '[RM]',
    'Back to dev',
];
const SOURCE_VERSION_REGEX = '(#define\s+PHP_AMQP_VERSION\s+)"(?P<version>' . VERSION_REGEX . ')"';

function re(string ...$regex): string
{
    return '@^' . str_replace('@', '\\@', implode('', $regex)) . '$@mD';
}

function gitFetch(): void
{
    `git fetch --all`;
}

function packageXml(): DOMDocument
{
    static $doc;

    if (!$doc) {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $doc->preserveWhiteSpace = false;
        $doc->load(PACKAGE_XML);
    }

    return $doc;
}

function savePackageXml(): void
{
    packageXml()->save(PACKAGE_XML);
    $packageXml = PACKAGE_XML;
    $pretty = `XMLLINT_INDENT="    " xmllint --format $packageXml`;
    file_put_contents(PACKAGE_XML, $pretty);
}

function getPackageVersion(): string
{
    $xml = simplexml_import_dom(packageXml());

    $release = $xml->version->release;

    assert(preg_match(re(VERSION_REGEX), $release));

    return $release;
}

function setPackageVersion(string $nextVersion): void
{
    $xml = simplexml_import_dom(packageXml());

    $xml->version->release = $nextVersion;
    $xml->version->api = $nextVersion;
}

function setChangelog(string $changelog): void
{
    $xml = simplexml_import_dom(packageXml());

    $noteNode = dom_import_simplexml($xml->notes);
    foreach ($noteNode->childNodes as $child) {
        $noteNode->removeChild($child);
    }
    $noteNode->appendChild(packageXml()->createCDATASection($changelog));
}

function modifyInteractive(string $text): string
{
    $file = tempnam(sys_get_temp_dir(), __FUNCTION__);
    file_put_contents($file, $text);


    $editor = $_SERVER['EDITOR'] ?? 'vim';
    $command = sprintf('%s %s > `tty`', escapeshellcmd($editor), escapeshellarg($file));

    system($command);

    $updatedText = trim(file_get_contents($file));

    if ($updatedText === '') {
        modifyInteractive($text);
    }

    return $updatedText;
}

function setDate(DateTimeImmutable $now): void
{
    $xml = simplexml_import_dom(packageXml());

    $xml->date = $now->format('Y-m-d');
    $xml->time = $now->format('H:i:s');
}

function addFilesToPackageXml(SimpleXMLElement $dir, string $expression, string $role): void {
    foreach (glob(BASE_DIR . $expression) as $file) {

        $file = str_replace(realpath(BASE_DIR) . '/', '', realpath($file));

        if ($file === 'config.h') {
            continue;
        }

        $child = $dir->addChild('file');
        $child->addAttribute('name', $file);
        $child->addAttribute('role', $role);
    }
}

function removeFromPackageXmlNodes(SimpleXMLElement $el, string $expression): void {
    $nodesToDelete = [];

    foreach ($el->children() as $file) {
        if (fnmatch($expression, (string) $file['name'])) {
            $nodesToDelete[] = $file;
        }
    }

    foreach ($nodesToDelete as $node) {
        $dom = dom_import_simplexml($node);
        $dom->parentNode->removeChild($dom);
    }
}

function updateFiles(): void {
    $xml = simplexml_import_dom(packageXml());
    $sourceExpression = '*.[ch]';
    $testsExpression = '*.phpt';
    $stubExpression = 'stubs/*.php';

    removeFromPackageXmlNodes($xml->contents->dir, $sourceExpression);
    removeFromPackageXmlNodes($xml->contents->dir, $testsExpression);
    removeFromPackageXmlNodes($xml->contents->dir, $stubExpression);
    addFilesToPackageXml($xml->contents->dir, $sourceExpression, 'src');
    addFilesToPackageXml($xml->contents->dir, 'tests/' . $testsExpression, 'test');
    addFilesToPackageXml($xml->contents->dir, $stubExpression, 'doc');
}

function getSourceVersion(): string
{
    $content = file_get_contents(HEADER_VERSION_FILE);

    assert(preg_match(re(SOURCE_VERSION_REGEX), $content, $matches));
    assert(preg_match(re(VERSION_REGEX), $matches['version']));

    return $matches['version'];
}

function setSourceVersion(string $nextVersion): void
{
    file_put_contents(
        HEADER_VERSION_FILE,
        preg_replace(
            re(SOURCE_VERSION_REGEX),
            '\1"' . $nextVersion . '"',
            file_get_contents(HEADER_VERSION_FILE)
        )
    );
}

function getPreviousVersion(): string
{
    $previousVersion = ltrim(trim(`git tag -l --sort=-v:refname | head -n1`), 'v');

    assert(preg_match(re(VERSION_REGEX), $previousVersion));

    return $previousVersion;
}

function versionToTag(string $version): string
{
    return sprintf('v%s', $version);
}

function isIgnoredMessage(string $message): bool
{
    foreach (COMMIT_MESSAGE_CHANGELOG_IGNORED as $pattern) {
        return strpos($message, $pattern) !== false;
    }

    return false;
}

function buildChangelog(string $nextTag, string $previousTag): string
{
    $commits = array_filter(
        explode("\n", trim(`git log --oneline ${previousTag}..origin/master --pretty=%h --no-merges`))
    );

    $changeLines = [];

    foreach ($commits as $commit) {
        $committerName = trim(`git log $commit^..$commit --pretty=%an`);
        $committerEmail = trim(`git log $commit^..$commit --pretty=%ae`);
        $message = trim(`git log $commit^..$commit --pretty=%s`);

        if (isIgnoredMessage($message)) {
            continue;
        }

        $issueId = null;
        if (preg_match('/^(?P<message>.*)\s+\(#(?P<issueId>\d+)\)$/', $message, $matches)) {
            $message = $matches['message'];
            $issueId = $matches['issueId'];
        }

        $url = $issueId ? sprintf(ISSUE_URL_TEMPLATE, $issueId): sprintf(COMMIT_URL_TEMPLATE, $commit);
        $committer = strpos($committerEmail, '@users.noreply.github.com') !== false
            ? $committerName
            : sprintf('%s <%s>', $committerName, $committerEmail);
        $changeLines[] = sprintf(' - %s (%s) (%s)', ucfirst($message), $committer, $url);
    }

    $changes = implode(PHP_EOL, $changeLines);
    $changelog = <<<EOT
$changes

For a complete list of changes see:
https://github.com/php-amqp/php-amqp/compare/${previousTag}...${nextTag}

EOT;

    return $changelog;
}

function archiveRelease(): void {
    $dom = packageXml();
    $xml = simplexml_import_dom($dom);

    $release = $dom->createElementNS('http://pear.php.net/dtd/package-2.0', 'release');

    $elements = ['date', 'time', 'version', 'stability', 'license', 'notes'];
    foreach ($elements as $element) {
        $release->appendChild(dom_import_simplexml($xml->{$element})->cloneNode(true));
    }

    $changelogDom = dom_import_simplexml($xml->changelog);
    $changelogDom->insertBefore($release, $changelogDom->firstChild);
}

function setStability(string $nextVersion): void
{
    $dom = packageXml();
    $xml = simplexml_import_dom($dom);

    $stability = preg_match(re('.*(?<stability>' . STABILITY_REGEX . ')'), $nextVersion, $matches)
        ? $matches['stability']
        : 'stable';
    $stability = $stability === 'dev' ? 'devel' : $stability;

    $xml->stability->release = $stability;
    $xml->stability->api = $stability;
}

function executeCommand(string $command): void {
    exec($command, $output, $returnCode);
    if ($returnCode !== 0) {
        printf("Could not execute command \"%s\"\n", $command);
        echo implode(PHP_EOL, $output);
        echo "\n";
        exit(1);
    }
}

function validatePackage(): void {
    executeCommand('pecl package-validate');
}

function peclPackage(int $step, string $nextVersion): void {
    executeCommand('pecl package');

    $archive = 'amqp-' . $nextVersion . '.tgz';
    if (!file_exists(BASE_DIR . $archive)) {
        echo "Could find $archive\n";
        exit(1);
    }

    printf("%d) Upload %s to PECL\n", $step, $archive);
}

function gitCommit(int $step, string $nextVersion, string $message): void {
    executeCommand(
        sprintf('git commit -m "[RM] %s %s" %s %s', $message, $nextVersion, HEADER_VERSION_FILE, PACKAGE_XML)
    );

    printf("%d) Run \"git push origin master\"\n", $step);
}

function gitTag(int $step, string $nextVersion): void {
    $nextTag = versionToTag($nextVersion);

    executeCommand("git tag ${nextTag}");

    printf("%d) Run \"git push origin %s\"\n", $step, $nextTag);
}
