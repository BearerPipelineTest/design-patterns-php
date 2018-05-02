<?php

namespace RefactoringGuru\Command\RealLife;

/**
 * Command Design Pattern
 *
 * Intent: Encapsulate a request as an object, thereby letting you parameterize
 * clients with different requests, queue or log requests, and support undoable
 * operations.
 *
 * Example: In this example the Command pattern is used to queue web scrapping
 * calls to IMDB website and execute them one by one. The queue itself is kept
 * in a database which helps to preserve commands between script launches.
 */

/**
 * Command Interface.
 */
interface Command
{
    public function execute();

    public function getId();

    public function getStatus();
}

/**
 * Base Command. Defines the code, common to all commands.
 */
abstract class WebScrapingCommand implements Command
{
    public $id;

    public $status = 0;

    /**
     * @var string URL for scraping.
     */
    public $url;

    protected $rawContent;

    public function __construct(string $url)
    {
        $this->url = $url;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function getURL()
    {
        return $this->url;
    }

    public function execute()
    {
        $html = $this->download();
        $this->parse($html);
        $this->complete();
    }

    public function download()
    {
        $html = file_get_contents($this->getURL());
        print("WebScrapingCommand: Downloaded {$this->url}\n");
        return $html;
    }

    abstract public function parse($html);

    public function complete()
    {
        $this->status = 1;
        Queue::get()->completeCommand($this);
    }
}

/**
 * Concrete Command.
 */
class IMDBGenresScrappingCommand extends WebScrapingCommand
{
    public function __construct()
    {
        $this->url = "https://www.imdb.com/feature/genre/";
    }

    /**
     * Extract all genres and their search URLs from the page:
     * https://www.imdb.com/feature/genre/
     */
    public function parse($html)
    {
        preg_match_all("|href=\"(https://www.imdb.com/search/title\?genres=.*?)\"|", $html, $matches);
        print("IMDBGenresScrappingCommand: Discovered " . count($matches[1]) . " genres.\n");

        foreach ($matches[1] as $genre) {
            Queue::get()->add(new IMDBGenrePageScrappingCommand($genre));
        }
    }
}

/**
 * Concrete Command.
 */
class IMDBGenrePageScrappingCommand extends WebScrapingCommand
{
    private $page;

    public function __construct($url, $page = 1)
    {
        parent::__construct($url);
        $this->page = $page;
    }

    public function getURL()
    {
        return $this->url . '?page=' . $this->page;
    }

    /**
     * Extract all movies from a page like this:
     * https://www.imdb.com/search/title?genres=sci-fi&explore=title_type,genres
     */
    public function parse($html)
    {
        preg_match_all("|href=\"(/title/.*?/)\?ref_=adv_li_tt\"|", $html, $matches);
        print("IMDBGenrePageScrappingCommand: Discovered " . count($matches[1]) . " movies.\n");

        foreach ($matches[1] as $moviePath) {
            $url = "https://www.imdb.com" . $moviePath;
            Queue::get()->add(new IMDBMovieScrappingCommand($url));
        }

        // Parse the next page URL.
        if (preg_match("|Next &#187;</a>|", $html)) {
            Queue::get()->add(new IMDBGenrePageScrappingCommand($this->url, $this->page + 1));
        }
    }
}

/**
 * Concrete Command.
 */
class IMDBMovieScrappingCommand extends WebScrapingCommand
{
    /**
     * Get the movie info from a page like this:
     * https://www.imdb.com/title/tt4154756/
     */
    public function parse($html)
    {
        if (preg_match("|<h1 itemprop=\"name\" class=\"\">(.*?)</h1>|", $html, $matches)) {
            $title = $matches[1];
        }
        print("IMDBMovieScrappingCommand: Parsed movie $title.\n");
    }
}

/**
 * Invoker. This is a very primitive implementation of the command queue. It
 * stores commands in the SQLite database.
 */
class Queue
{
    private $db;

    public function __construct()
    {
        $this->db = new \SQLite3('commands.sqlite',
            SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);

        $this->db->query('CREATE TABLE IF NOT EXISTS "commands" (
            "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            "command" TEXT,
            "status" INTEGER
        )');
    }

    public function isEmpty()
    {
        $query = 'SELECT COUNT("id") FROM "commands" WHERE status = 0';
        return $this->db->querySingle($query) === 0;
    }

    public function add(Command $command)
    {
        $query = 'INSERT INTO commands (command, status) VALUES (:command, :status)';
        $statement = $this->db->prepare($query);
        $statement->bindValue(':command', base64_encode(serialize($command)));
        $statement->bindValue(':status', $command->getStatus());
        $statement->execute();
    }

    public function getCommand(): Command
    {
        $query = 'SELECT * FROM "commands" WHERE "status" = 0 LIMIT 1';
        $record = $this->db->querySingle($query, true);
        $command = unserialize(base64_decode($record["command"]));
        $command->id = $record['id'];
        return $command;
    }

    public function completeCommand(Command $command)
    {
        $query = 'UPDATE commands SET status = :status WHERE id = :id';
        $statement = $this->db->prepare($query);
        $statement->bindValue(':status', $command->getStatus());
        $statement->bindValue(':id', $command->getId());
        $statement->execute();
    }

    public function work()
    {
        while (!$this->isEmpty()) {
            $command = $this->getCommand();
            $command->execute();
        }
    }

    /**
     * Queue object is a Singleton.
     */
    public static function get(): Queue
    {
        static $instance;
        if (!$instance) {
            $instance = new Queue();
        }
        return $instance;
    }
}

/**
 * Client code.
 */

$queue = Queue::get();

if ($queue->isEmpty()) {
    $queue->add(new IMDBGenresScrappingCommand());
}

$queue->work();