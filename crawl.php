<?php

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Yaml\Parser;
use Quan\Net\Url;
use Quan\Config\HierarchyLoader;

if ($argc >= 2) {
    crawl($argv[1]);
}

function crawl($starturl)
{
    $config = (new HierarchyLoader('config/'))->getConfig();
    $url = $starturl;
    $domain = extractDomain($url);

    // get an in memory database connection
    $conn = DriverManager::getConnection(['pdo' => new PDO('sqlite::memory:')]);
    $conn->exec("CREATE TABLE urls (
                url VARCHAR NOT NULL  PRIMARY KEY ,
                effectiveUrl VARCHAR NULLABLE ,
                httpStatus INT NULLABLE
                );");

    // insert initial url into database
    $conn->insert('urls', array('url' => $url));
    // number of entries with status code null
    while ($conn->fetchColumn('SELECT COUNT (url) FROM urls WHERE httpStatus is NULL') > 0) {
        // get first entry without status code
        $url = $conn->fetchColumn('SELECT url FROM urls WHERE httpStatus is NULL');
        // request initial url and get the status code
        $client = new Client(['defaults' => [
            'exceptions' => false
        ]]);
        $response = $client->get($url);
        // update status code in database for this url
        $conn->update('urls', array('httpStatus' => $response->getStatusCode(), 'effectiveUrl' => $response->getEffectiveUrl()), array('url' => $url));
        // get all the a tags from the response body
        $body = $response->getBody()->getContents();
        $crawler = new Crawler($body);
        $links = $crawler->filter('a');
        // get the urls and store the ones that are within the domain in the database
        foreach ($links as $node) {
            $linkUrl = new Url($url);
            $linkUrl->setUrl($node->getAttribute('href'));
            if ($domain == extractDomain($linkUrl)) {
                try {
                    $conn->insert('urls', array('url' => $linkUrl));
                }
                catch(Exception $e) {

                }
            }
        }
    }
    // get all the urls with a 404 httpStatus
    $urlsWithStatus404 = $conn->fetchAll('SELECT effectiveUrl FROM urls WHERE httpStatus = 404 GROUP BY effectiveUrl');
    $body ='Hallo,' . "\n" . "\n" . 'anbei das Ergebnis der letzten Überprüfung. Es wurden ' . count($urlsWithStatus404) .
        ' Seiten mit HTTP Status 404 gefunden.' . "\n" ."\n" .
        'Besten Gruß' . "\n" .
        'Quan Digital';
    echo $body;

    // send mail
    $transport = Swift_SmtpTransport::newInstance($config['host'], $config['port'], $config['security'])
        ->setUsername($config['user'])
        ->setPassword($config['password']);
    $mailer = Swift_Mailer::newInstance($transport);
    $message = Swift_Message::newInstance($config['subject'])
        ->setFrom(array($config['from']))
        // add address of recipient
        ->setTo(array($config['to']))
        //->setCC(array($config['CC']))
        ->setReplyTo(array($config['replyTo']))
        ->setBody($body);
    $attachment = Swift_Attachment::newInstance(implode("\n", array_column($urlsWithStatus404, 'effectiveUrl')), $config['attachment'], 'text/csv');
    $message->attach($attachment);
    $mailer->send($message);
}

function extractDomain($url)
{
    $domain = parse_url($url , PHP_URL_HOST);
    if (preg_match('/(?P<domain>[a-z0-9][a-z0-9\-]{1,63}\.[a-z\.]{2,6})$/i', $domain, $list)) {
        return substr($list['domain'], 0,strpos($list['domain'], "."));
    }

    return false;
}
