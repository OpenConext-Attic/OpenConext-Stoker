<?php

namespace OpenConext\Stoker\Command;

use ass\XmlSecurity\DSig;
use ass\XmlSecurity\Key;
use Cilex\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class StokeCommand extends Command
{
    const METADATA_LOCAL_CACHE_FILENAME = 'saml2-metadata.xml';

    private $metadataDirectory;
    private $metadataSourcePath;
    /**
     * @var Key
     */
    private $publicKey;

    protected function configure()
    {
        $this
            ->setName('stoke')
            ->setDescription('Synchronize a given metadatafile to a given directory.')
            ->addArgument('metadataPath', InputArgument::REQUIRED, 'Where can I get the metadata (URL or path)?')
            ->addArgument('directory', InputArgument::REQUIRED, 'Where do I put my output?')
            ->addOption('certPath', 'cp', InputOption::VALUE_OPTIONAL, 'Path to trusted certificate to verify metadata with, e.g. /etc/stoker/mds.edugain.org or even https://www.edugain.org/mds-2014.cer')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->verifyDestinationDirectory($input->getArgument('directory'));
        $this->verifyMetadataPath($input->getArgument('metadataPath'));
        $this->verifyCertPath($input->getOption('certPath'));

        $metadataIndex = MetadataIndex::load($this->metadataDirectory);
        if (!$metadataIndex) {
            $metadataIndex = $this->updateSourceCache();
        }

        // If the cache has already expired.
        if ($metadataIndex->isCacheExpired()) {
            // Renew the cache and try again.
            return $this->updateSourceCache();
        }

        // If the metadata has validity and the validity has expired.
        if ($metadataIndex->isValidityExpired()) {
            // Renew the cache and try again.
            return $this->updateSourceCache();
        }

        // Cache is still valid, return the index from disk.
        return $metadataIndex;
    }

    private function verifyDestinationDirectory($directory)
    {
        if (!file_exists($directory)) {
            $isCreated = @mkdir($directory, 0700, true);
            if (!$isCreated) {
                throw new \InvalidArgumentException(
                    "'$directory' does not exist and can not be created by the current user. Try: sudo mkdir -p \"$directory\""
                );
            }
        }
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException(
                "'$directory' exists but is not a directory."
            );
        }
        if (!is_writable($directory)) {
            throw new \InvalidArgumentException(
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
            throw new \InvalidArgumentException(
                "Unable to fetch cert from URL '$certPath' because php.ini setting 'allow_url_fopen' is set to Off"
            );
        }
        if (!$pathIsUrl && !file_exists($certPath)) {
            throw new \InvalidArgumentException(
                "Unable to fetch certificate from path '$certPath', does not exist.'"
            );
        }
        if ($pathIsUrl && !$this->urlExists($certPath)) {
            throw new \InvalidArgumentException(
                "Unable to fetch certificate from url '$certPath', does not return 200.'"
            );
        }

        $this->publicKey = new Key\RsaSha256(Key::TYPE_PUBLIC, $certPath, true);
    }

    private function verifyMetadataPath($path)
    {
        $pathIsUrl = (bool) parse_url($path);
        if ($pathIsUrl && !ini_get('allow_url_fopen')) {
            throw new \InvalidArgumentException(
                "Unable to fetch metadata from URL '$path' because php.ini setting 'allow_url_fopen' is set to Off"
            );
        }
        if (!$pathIsUrl && !file_exists($path)) {
            throw new \InvalidArgumentException(
                "Unable to fetch metadata from path '$path', does not exist."
            );
        }
        if ($pathIsUrl && !$this->urlExists($path)) {
            throw new \InvalidArgumentException(
                "Unable to fetch metadata from url '$path', does not return 200 status code."
            );
        }

        $this->metadataSourcePath = $path;
    }

    private function updateSourceCache()
    {
        $metadataFile = $this->metadataDirectory . static::METADATA_LOCAL_CACHE_FILENAME;

        $this->downloadLargeFile($this->metadataSourcePath, $metadataFile);

        // @todo So we carefully try not to load the entire document in memory but then we have to verify
        //       the signature and because PHP has no streaming libraries for this we have to load it in memory anyway.
        $document = new \DOMDocument();
        $document->load($metadataFile);
        $signature = DSig::locateSignature($document->firstChild);
        if (!DSig::verifyDocumentSignature($signature, $this->publicKey)) {
            throw new \RuntimeException("Unable to verify signature on document at '$metadataFile'");
        }

        // Start the Streaming XML Reader.
        $reader = new \XMLReader();

        // Try to open the URL
        if (!$reader->open($metadataFile)) {
            throw new \RuntimeException("Unable to open: $metadataFile");
        }

        // Read the first node.
        if (!$reader->read()) {
            throw new \RuntimeException('Unable to read root node. File: ' . $metadataFile);
        }
        // Make sure it's an EntitiesDescriptor
        if ($reader->localName !== 'EntitiesDescriptor') {
            throw new \RuntimeException('Root node is not an EntitiesDescriptor. File: ' . $metadataFile);
        }

        // Get the time until we are allowed to cache this file and when it expires.
        // (see SAML Metadata spec for semantics)
        $cacheDuration = $reader->getAttribute('cacheDuration');
        if (!$cacheDuration) {
            // Default to caching for 6 hours.
            $cacheDuration = 'PT6H';
        }
        $cacheUntil = new \DateTime('@' . (time() + $this->durationToUnixTimestamp($cacheDuration)));

        $validUntil = $reader->getAttribute('validUntil');
        if ($validUntil) {
            $validUntil = new \DateTime($validUntil);
        }

        $metadataIndex = new MetadataIndex(
            $this->metadataDirectory,
            $cacheUntil,
            new \DateTime(),
            $validUntil ? $validUntil : null
        );
        $metadataIndex->save();

        // Read until we're IN the EntitiesDescriptor
        do {
            $read = $reader->read();
        }
        while ($read && $reader->depth < 1);

        if ($reader->depth !== 1) {
            throw new \RuntimeException(
                'Unable to descend in the EntitiesDescriptor, no EntityDescriptor elements? File: ' . $metadataFile
            );
        }

        // Read to the first EntityDescriptor
        if ($reader->localName !== 'EntityDescriptor') {
            $read = $reader->next('EntityDescriptor');
        }

        if (!$read) {
            throw new \RuntimeException(
                'Unable to read to the first EntityDescriptor. No EntityDescriptor elements? File: ' . $metadataFile
            );
        }

        do {
            $entityXml = $reader->readOuterXml();

            // Get the entityID for the Entity
            $entityId = $this->getEntityIdFromXml($entityXml);
            $metadataIndex->addEntityId($entityId);

            $filePath = $this->getFilePathForEntityId($entityId);

            if (!file_exists($filePath) || md5($entityXml) !== md5_file($filePath)) {
                file_put_contents($filePath, $entityXml);
            }
        }
        while ($reader->next('EntityDescriptor'));

        $metadataIndex->save();

        return $metadataIndex;
    }

    /**
     * @param \DateInterval $dateInterval
     * @return int seconds
     */
    private function durationToUnixTimestamp($dateInterval)
    {
        $reference = new \DateTime();
        $endTime = $reference->add(new \DateInterval($dateInterval));

        return $endTime->getTimestamp() - time();
    }

    private function downloadLargeFile($from, $to)
    {
        $rh = fopen($from, 'rb');
        if (!$rh) {
            throw new \RuntimeException("Unable to open '$from'");
        }
        $wh = fopen($to, 'w+b');
        if (!$wh) {
            throw new \RuntimeException("Unable to open '$to'");
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
        return (bool) preg_match("|200|", $headers[0]);
    }

    private function getEntityIdFromXml($entityXml)
    {
        $document = new \DOMDocument();
        $document->loadXML($entityXml);
        $directory = new \DOMXPath($document);
        $directory->registerNamespace('samlmd', 'urn:oasis:names:tc:SAML:2.0:metadata');
        $entityIdResults = $directory->query('/samlmd:EntityDescriptor/@entityID');
        if ($entityIdResults->length !== 1) {
            throw new \RuntimeException(
                "{$entityIdResults->length} results found for an entityID attribute on: " . $entityXml
            );
        }
        return $entityIdResults->item(0)->nodeValue;
    }

    /**
     * @param $entityId
     * @return string
     */
    private function getFilePathForEntityId($entityId)
    {
        $filePath = $this->metadataDirectory . md5($entityId) . '.xml';
        return $filePath;
    }
}