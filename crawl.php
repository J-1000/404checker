<?php

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\DomCrawler\Crawler;
use Quan\Net\Url;
use Symfony\Component\Yaml\Parser;

if ($argc >= 2) {
    crawl($argv[1]);
}

function crawl($starturl) {
    $yaml = new Parser();
    $config = $yaml->parse(file_get_contents(__DIR__.'/config.yml'));
    $url = $starturl;
    $domain = extractDomain($url);
    // get an in memory database connection
    $conn = DriverManager::getConnection(['pdo' => new PDO('sqlite::memory:')]);
    $conn->exec("CREATE TABLE urls (
                url VARCHAR NOT NULL  PRIMARY KEY ,
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
        $httpStatus = $response->getStatusCode();
        // update status code in database for this url
        $conn->update('urls', array('httpStatus' => $httpStatus), array('url' => $url));
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
    $urlsWithStatus404 = $conn->fetchAll('SELECT url FROM urls WHERE httpStatus = 404 ORDER BY url');
    $numberOf404s = $conn->fetchColumn('SELECT COUNT (url) FROM urls WHERE httpStatus = 404');
    $body ='Hallo,' . "\n" . "\n" . 'anbei das Ergebnis der letzten Überprüfung. Es wurden ' . $numberOf404s .
            ' Seiten mit HTTP Status 404 gefunden.' . "\n" ."\n" .
            'Besten Gruß' . "\n" .
            'Quan Digital';
    // put together csv file
    $csv = fopen('404Pages.csv', 'w');
    foreach ($urlsWithStatus404 as $url404) {
        fputcsv($csv, $url404);
    }
    fclose($csv);
    echo $body;
    // send mail
    $transport = Swift_SmtpTransport::newInstance('smtp.office365.com', 587, 'tls')
        ->setUsername('mail@quandigital.com')
        ->setPassword('99Blogger!');
    $mailer = Swift_Mailer::newInstance($transport);
    $message = Swift_Message::newInstance('404 Pages')
        ->setFrom(array('mail@quandigital.com'))
        // add address of recipient
        ->setTo(array(''))
        //->setCC(array('tech@quandigital.com'))
        ->setReplyTo(array('tech@quandigital.com'))
        ->setBody($body);

    $swiftAttachment = Swift_Attachment::fromPath('404Pages.csv');
    $message->attach($swiftAttachment);

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

function test() {
    return false;
}