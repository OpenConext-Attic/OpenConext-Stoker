<?php

namespace OpenConext\Stoker\Command;

use DateInterval;
use DateTime;
use DOMDocument;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;
use OpenConext\Component\StokerMetadata\MetadataIndex;
use OpenConext\Component\StokerMetadata\MetadataIndex\Entity;
use RuntimeException;
use XMLReader;

use ass\XmlSecurity\DSig;
use ass\XmlSecurity\Key;

use Cilex\Command\Command;

use Monolog\ErrorHandler;
use Monolog\Handler\NativeMailerHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Logger;

use Symfony\Bridge\Monolog\Handler\ConsoleHandler;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Stoke the SAML2 fire.
 *
 * Basically something that synchronizes between a local cache in an optimized format and
 * a remote SAML2 metadata document.
 *
 * @package OpenConext\Stoker\Command
 */
class StokeCommand extends Command
{
    /**
     * Name of the index file.
     */
    const METADATA_LOCAL_CACHE_FILENAME = 'saml2-metadata.xml';

    /**
     * Directory to cache the metadata.
     *
     * @var string
     */
    private $metadataDirectory;

    /**
     * Where can we get the metadata to load? (URL or path, anything accepted by PHPs streams)
     *
     * @var string
     */
    private $metadataSourcePath;

    /**
     * Public Key to verify the XML with.
     *
     * @var Key
     */
    private $publicKey;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('stoke')
            ->setDescription('Synchronize a given metadatafile to a given directory.')
            ->addArgument('metadataPath', InputArgument::REQUIRED, 'Where can I get the metadata (URL or path)?')
            ->addArgument('directory', InputArgument::REQUIRED, 'Where do I put my output?')
            ->addOption('certPath', 'cp', InputOption::VALUE_REQUIRED, 'Path to trusted certificate to verify metadata with, e.g. /etc/stoker/mds.edugain.org or even https://www.edugain.org/mds-2014.cer')
            ->addOption('mailErrors', 'me', InputOption::VALUE_REQUIRED, 'Mail any errors that occur to this email address')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $directory      = $input->getArgument('directory');
        $metadataPath   = $input->getArgument('metadataPath');
        $caPath         = $input->getOption('certPath');
        $mailErrors     = $input->getOption('mailErrors');

        $this->initLogger($output, $mailErrors);

        $this->logger->addNotice(
            "Starting Stoke",
            array('directory' => $directory, 'metadatPath' => $metadataPath, 'caPath' => $caPath, 'mailErrors' => $mailErrors)
        );

        $this->verifyDestinationDirectory($directory);
        $this->verifyMetadataPath($metadataPath);
        $this->verifyCertPath($caPath);

        $metadataIndex = MetadataIndex::load($this->metadataDirectory);
        if (!$metadataIndex) {
            $this->logger->addDebug("(Re)newing caching because no metadata index yet.");
            $metadataIndex = $this->updateSourceCache();
        }

        if ($metadataIndex->isCacheExpired()) {
            $this->logger->addDebug("Renewing cache because expired.");
            $this->updateSourceCache();
            $this->logger->addNotice("Done. " . \PHP_Timer::resourceUsage());
            return 0;
        }

        // If the metadata has validity and the validity has expired.
        if ($metadataIndex->isValidityExpired()) {
            $this->logger->addDebug("Renewing cache because no longer valid.");
            // Renew the cache and try again.
            $this->updateSourceCache();
            $this->logger->addNotice("Done. " . \PHP_Timer::resourceUsage());
            return 0;
        }

        $this->logger->addDebug("Cache is still valid");
        $this->logger->addNotice("Done. " . \PHP_Timer::resourceUsage());
        return 0;
    }

    private function initLogger(OutputInterface $output, $mailTo = '')
    {
        \PHP_Timer::start();

        $this->logger = new Logger('ocstoke');

        // First we log to syslog.
        $this->logger->pushHandler(
            new SyslogHandler(
                'ocstoke', // ident, name in syslogs.
                LOG_USER,  // facility
                Logger::DEBUG  // Minimum log level to report
            )
        );

        // Then we log to the console output
        $this->logger->pushHandler(
            new ConsoleHandler(
                $output
            )
        );

        // Finally, for errors we may send an e-mail.
        if ($mailTo) {
            $this->logger->pushHandler(
                new NativeMailerHandler(array($mailTo), 'OpenConext Stoker Error', 'support@openconext.org')
            );
        }

        // And make sure that PHP errors and uncaught exceptions are all handled by this logger.
        ErrorHandler::register($this->logger);
    }

    private function verifyDestinationDirectory($directory)
    {
        if (!file_exists($directory)) {
            $this->logger->notice("Directory '$directory' does not exist yet, creating.");
            $isCreated = @mkdir($directory, 0700, true);
            if (!$isCreated) {
                throw new InvalidArgumentException(
                    "'$directory' does not exist and can not be created by the current user. Try: sudo mkdir -p \"$directory\""
                );
            }
        }
        if (!is_dir($directory)) {
            throw new InvalidArgumentException(
                "'$directory' exists but is not a directory."
            );
        }
        if (!is_writable($directory)) {
            throw new InvalidArgumentException(
                "'$directory' is not a writable path."
            );
        }
        $this->metadataDirectory = realpath($directory) . DIRECTORY_SEPARATOR;
    }

    private function verifyCertPath($certPath)
    {
        if (!trim($certPath)) {
            return;
        }

        $pathIsUrl = (bool) parse_url($certPath);
        if ($pathIsUrl && !ini_get('allow_url_fopen')) {
            throw new InvalidArgumentException(
                "Unable to fetch cert from URL '$certPath' because php.ini setting 'allow_url_fopen' is set to Off"
            );
        }
        if (!$pathIsUrl && !file_exists($certPath)) {
            throw new InvalidArgumentException(
                "Unable to fetch certificate from path '$certPath', does not exist.'"
            );
        }
        if ($pathIsUrl && !$this->urlExists($certPath)) {
            throw new InvalidArgumentException(
                "Unable to fetch certificate from url '$certPath', does not return 200.'"
            );
        }

        $this->publicKey = new Key\RsaSha256(Key::TYPE_PUBLIC, $certPath, true);
    }

    private function verifyMetadataPath($path)
    {
        $pathIsUrl = (bool) parse_url($path);
        if ($pathIsUrl && !ini_get('allow_url_fopen')) {
            throw new InvalidArgumentException(
                "Unable to fetch metadata from URL '$path' because php.ini setting 'allow_url_fopen' is set to Off"
            );
        }
        if (!$pathIsUrl && !file_exists($path)) {
            throw new InvalidArgumentException(
                "Unable to fetch metadata from path '$path', does not exist."
            );
        }
        if ($pathIsUrl && !$this->urlExists($path)) {
            throw new InvalidArgumentException(
                "Unable to fetch metadata from url '$path', does not return 200 status code."
            );
        }

        $this->metadataSourcePath = $path;
    }

    /**
     * @todo atomicity!
     *
     * @return MetadataIndex
     * @throws RuntimeException
     */
    private function updateSourceCache()
    {
        $metadataFile = $this->metadataDirectory . static::METADATA_LOCAL_CACHE_FILENAME;

        $this->downloadLargeFile($this->metadataSourcePath, $metadataFile);

        // @todo So we carefully try not to load the entire document in memory but then we have to verify
        //       the signature and because PHP has no streaming libraries for this we have to load it in memory anyway.
        $document = new DOMDocument();
        $document->load($metadataFile);
        $signature = DSig::locateSignature($document->firstChild);
        if (!DSig::verifyDocumentSignature($signature, $this->publicKey)) {
            throw new RuntimeException("Unable to verify signature on document at '$metadataFile'");
        }
        $this->logger->debug("Signature verified.");

        // Start the Streaming XML Reader.
        $reader = new XMLReader();

        // Try to open the URL
        if (!$reader->open($metadataFile)) {
            throw new RuntimeException("Unable to open: $metadataFile");
        }

        // Read the first node.
        if (!$reader->read()) {
            throw new RuntimeException('Unable to read root node. File: ' . $metadataFile);
        }
        // Make sure it's an EntitiesDescriptor
        if ($reader->localName !== 'EntitiesDescriptor') {
            throw new RuntimeException('Root node is not an EntitiesDescriptor. File: ' . $metadataFile);
        }

        // Get the time until we are allowed to cache this file and when it expires.
        // (see SAML Metadata spec for semantics)
        $cacheDuration = $reader->getAttribute('cacheDuration');
        if (!$cacheDuration) {
            $this->logger->notice("No cacheDuration specified, caching for 6 hours by default");
            // Default to caching for 6 hours.
            $cacheDuration = 'PT6H';
        }
        $cacheUntil = new DateTime('@' . (time() + $this->durationToUnixTimestamp($cacheDuration)));

        $validUntil = $reader->getAttribute('validUntil');
        if ($validUntil) {
            $validUntil = new DateTime($validUntil);
        }

        $metadataIndex = new MetadataIndex(
            $this->metadataDirectory,
            $cacheUntil,
            new DateTime(),
            $validUntil ? $validUntil : null
        );

        // Read until we're IN the EntitiesDescriptor
        do {
            $read = $reader->read();
        }
        while ($read && $reader->depth < 1);

        if ($reader->depth !== 1) {
            throw new RuntimeException(
                'Unable to descend in the EntitiesDescriptor, no EntityDescriptor elements? File: ' . $metadataFile
            );
        }

        // Read to the first EntityDescriptor
        if ($reader->localName !== 'EntityDescriptor') {
            $read = $reader->next('EntityDescriptor');
        }

        if (!$read) {
            throw new RuntimeException(
                'Unable to read to the first EntityDescriptor. No EntityDescriptor elements? File: ' . $metadataFile
            );
        }

        do {
            $entityXml = $reader->readOuterXml();

            // Transform the XML to an Entity model.
            $entity = $this->getEntityFromXml($entityXml);

            if ($entity) {
                $metadataIndex->addEntity($entity);

                $filePath = $this->getFilePathForEntityId($entity->entityId);

                if (!file_exists($filePath) || md5($entityXml) !== md5_file($filePath)) {
                    file_put_contents($filePath, $entityXml);
                }
            }
        }
        while ($reader->next('EntityDescriptor'));

        $metadataIndex->save();

        return $metadataIndex;
    }

    /**
     * @param mixed $dateInterval
     * @return int seconds
     */
    private function durationToUnixTimestamp($dateInterval)
    {
        $reference = new DateTime();
        $endTime = $reference->add(new DateInterval($dateInterval));

        return $endTime->getTimestamp() - time();
    }

    private function downloadLargeFile($from, $to)
    {
        $this->logger->debug("Downloading '$from' to '$to', 4Kb at a time.");
        $rh = fopen($from, 'rb');
        if (!$rh) {
            throw new RuntimeException("Unable to open '$from'");
        }
        $wh = fopen($to, 'w+b');
        if (!$wh) {
            throw new RuntimeException("Unable to open '$to'");
        }

        while (!feof($rh)) {
            if (fwrite($wh, fread($rh, 4096)) === FALSE) {
                return false;
            }
            flush();
        }

        fclose($rh);
        fclose($wh);
        return true;
    }

    private function urlExists($url)
    {
        $headers = @get_headers($url);
        $this->logger->debug("When checking if '$url' exists it gave '{$headers[0]}'");
        return (bool) preg_match("|200|", $headers[0]);
    }

    /**
     * Note that the spec supports multiple IDPSSODescriptors and SPSSODescriptors. But OpenConext engine does not.
     * EduGain currently has no entities with multiple IDP / SP roles so we're betting on it
     * not being too big of a problem in the future.
     *
     * @param string $entityXml
     * @return Entity
     * @throws RuntimeException
     */
    private function getEntityFromXml($entityXml)
    {
        $document = new DOMDocument();
        $document->loadXML($entityXml);

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('md', 'urn:oasis:names:tc:SAML:2.0:metadata');
        $xpath->registerNamespace('mdui', 'urn:oasis:names:tc:SAML:metadata:ui');
        $entityIdResults = $xpath->query('/md:EntityDescriptor/@entityID');
        if ($entityIdResults->length !== 1) {
            throw new RuntimeException(
                "{$entityIdResults->length} results found for an entityID attribute on: " . $entityXml
            );
        }
        $entityId = $entityIdResults->item(0)->nodeValue;

        $types = array();
        $displayNames = array();

        if ($xpath->query('/md:EntityDescriptor/md:IDPSSODescriptor')->length > 0) {
            $types[] = 'idp';
            /** @var DOMNode[] $displayNameNodes */
            $displayNameNodes = $xpath->query(
                '/md:EntityDescriptor/md:IDPSSODescriptor/md:Extensions/mdui:UIInfo/mdui:DisplayName'
            );
            foreach ($displayNameNodes as $displayNameNode) {
                $lang = $displayNameNode->attributes->getNamedItem('lang')->textContent;
                $content = $displayNameNode->textContent;
                $displayNames[$lang] = $content;
            }
        }
        if ($xpath->query('/md:EntityDescriptor/md:SPSSODescriptor')->length > 0) {
            $types[] = 'sp';
            /** @var DOMNode[] $displayNameNodes */
            $displayNameNodes = $xpath->query(
                '/md:EntityDescriptor/md:SPSSODescriptor/md:Extensions/mdui:UIInfo/mdui:DisplayName'
            );
            foreach ($displayNameNodes as $displayNameNode) {
                $lang = $displayNameNode->attributes->getNamedItem('lang')->textContent;
                $content = $displayNameNode->textContent;
                $displayNames[$lang] = $content;
            }
        }

        if (empty($types)) {
            $this->logger->addNotice("Entity '$entityId' is neither idp or sp!");
            return;
        }

        // If the SP / IDP doesn't have a DisplayName in it's role (which is not required, it's a SHOULD) we
        // try the OrganizationDisplayName.
        if (empty($displayNames)) {
            $displayNameNodes = $xpath->query('/md:EntityDescriptor/md:OrganizationDisplayName');
            /** @var DOMNode[] $displayNameNodes */
            foreach ($displayNameNodes as $displayNameNode) {
                $lang = $displayNameNode->attributes->getNamedItem('lang')->textContent;
                $content = $displayNameNode->textContent;
                $displayNames[$lang] = $content;
            }
        }

        // If we don't even have an OrganizationDisplayName then we just use the entityID which is guaranteed to
        // be there (also guaranteed not to be user friendly, but it's better than nothing).
        if (empty($displayNames)) {
            $displayNames[] = $entityId;
        }

        $displayNameEn = $this->pickDisplayName($displayNames, array('en', 'nl'));
        $displayNameNl = $this->pickDisplayName($displayNames, array('nl', 'en'));

        $this->logger->debug(
            "Entity found.",
            array(
                'entityID' => $entityId,
                'types' => $types,
                'displayNameNl' => $displayNameNl,
                'displayNameEn'=>$displayNameEn
            )
        );

        $entity = new Entity($entityId, $types, $displayNameEn, $displayNameNl);
        return $entity;
    }

    protected function pickDisplayName(array $displayNames, array $options)
    {
        foreach ($options as $option) {
            if (isset($displayNames[$option])) {
                return $displayNames[$option];
            }
        }

        return array_shift($displayNames);
    }

    /**
     * @param string $entityId
     * @return string
     */
    private function getFilePathForEntityId($entityId)
    {
        $filePath = $this->metadataDirectory . md5($entityId) . '.xml';
        return $filePath;
    }
}
