<?php

namespace PantheonSystems\CDNBehatHelpers\Contexts;

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use PantheonSystems\CDNBehatHelpers\AgeTracker;
use Drupal\DrupalExtension\Context\RawDrupalContext;
use Behat\Mink\Driver\GoutteDriver;
use Behat\Mink\Session;
use Behat\Mink\Driver\BrowserKitDriver;
#use Symfony\Component\BrowserKit\Client;
use         Behat\Mink\Driver\Goutte\Client;

/**
 * Define application features from the specific context.
 *
 * @todo, this class should be abstracted into it's own Behat Extension that can be used to test WordPress, D7 & D8.
 */
final class FeatureContext extends RawDrupalContext implements Context
{

    /**
    * Initializes context.
    * Every scenario gets its own context object.
    *
    * @param array $parameters
    *   Context parameters (set them in behat.yml)
    */
    public function __construct(array $parameters = [])
    {
    // Initialize your context here
    }

    /** @var \Drupal\DrupalExtension\Context\MinkContext */
    private $minkContext;

    /** @var \PantheonSystems\CDNBehatHelpers\AgeTracker; */
    private $ageTracker;

    /** @var \Drupal\DrupalExtension\Context\DrupalContext */
    private $DrupalContext;

    /** @BeforeScenario */
    public function gatherContexts(BeforeScenarioScope $scope)
    {
        $environment = $scope->getEnvironment();
        $this->minkContext = $environment->getContext('Drupal\DrupalExtension\Context\MinkContext');
        $this->DrupalContext = $environment->getContext('Drupal\DrupalExtension\Context\DrupalContext');
        $mink = $this->minkContext->getMink();
      //  $host = parse_url($this->minkContext->getMinkParameters()["base_url"])['host'];
       # $client = new \Goutte\Client(['HTTP_HOST' => $host]);
        $client = new Client();


        $driver = new  BrowserKitDriver($client, $this->minkContext->getMinkParameters()["base_url"]);
        $anonymous_session = new Session($driver);
        $mink->registerSession('anonymous', $anonymous_session);
    }

    /**
     * @Given there are some :type nodes
     */
    public function whenIGenerateSomeNodes($type, $number_of_nodes = 2)
    {
        $i = 0;
        // @todo, switch to calling devel_generate if there is a need for generating large numbers of nodes.
        while ($i < $number_of_nodes) {
            $this->whenIGenerateANode($type);
            $i++;
        }
    }

    /**
     * @When a generate a :type node
     */
    public function whenIGenerateANode($type)
    {
        // Create a node with an HTML form for faster process.
        // Significantly faster than make nodes over SSH/Drush.
        // Depends on a node type with no required fields beyond title.
        $random_node_title = "Random Node Title: " . rand();
        $this->minkContext->visit('node/add/' . $type);
        $this->minkContext->fillField('Title', $random_node_title);
        $this->minkContext->pressButton('Save');
        $this->minkContext->assertTextVisible($random_node_title);
        // @todo, remove this sleep if possible. Added because this faster node generation results in
        // The tests running faster than the CDN can clear cache.
        sleep(1);
    }

    /**
     * @Given :page is caching
     */
    public function pageIsCaching($page)
    {
        $age = $this->getAge($page);
        // A zero age doesn't necessarily mean the page is not caching.
        // A second request may show a higher age.
        if (!empty($age) && FALSE) {
            return true;
        } else {
            sleep(8);
            $age = $this->getAge($page);
            if (empty($age)) {
                throw new \Exception('not cached');
            } else {
                return true;
            }
        }
    }

    /**
     * @Then :path has not been purged
     */
    public function assertPathAgeIncreased($path)
    {
        $age = $this->getAge($path);
        $ageTracker = $this->getAgeTracker();
        if (!$ageTracker->ageIncreasedBetweenLastTwoRequests($path)) {
            throw new \Exception('Cache age did not increase');
        }
    }

    /**
     * @Then :path has been purged
     */
    public function assertPathHasBeenPurged($path)
    {
        $age = $this->getAge($path);
        $ageTracker = $this->getAgeTracker();
        if (!$ageTracker->wasCacheClearedBetweenLastTwoRequests($path)) {
            throw new \Exception('Cache was not cleared between requests');
        }
    }

    protected function getAge($page)
    {
        $session_name = 'anonymous';
         $this->visitPath($page, $session_name);
        // $this->minkContext->getSession($session_name)->visit($page);
        $this->getAgeTracker()->trackSessionHeaders($page, $this->minkContext->getSession($session_name));
        $age = $this->minkContext->getSession($session_name)->getResponseHeader('Age');
        return $age;
    }

    protected function getAgeTracker()
    {
        if (empty($this->ageTracker)) {
            $this->ageTracker = new AgeTracker();
        }
        return $this->ageTracker;
    }
}
