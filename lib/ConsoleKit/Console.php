<?php
/**
 * ConsoleKit
 * Copyright (c) 2012 Maxime Bouroumeau-Fuseau
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @author Maxime Bouroumeau-Fuseau
 * @copyright 2012 (c) Maxime Bouroumeau-Fuseau
 * @license http://www.opensource.org/licenses/mit-license.php
 * @link http://github.com/maximebf/ConsoleKit
 */
 
namespace ConsoleKit;

use Closure,
    DirectoryIterator;

/**
 * Registry of available commands and command runner
 */
class Console implements TextWriter
{
    /** @var array */
    protected $commands = array();

    /** @var OptionsParser */
    protected $optionsParser;

    /** @var TextWriter */
    protected $textWriter;

    /** @var bool */
    protected $exitOnException = true;

    /** @var string */
    protected $helpCommand = 'help';

    /** @var string */
    protected $helpCommandClass = 'ConsoleKit\HelpCommand';

    /**
     * @param array $commands
     */
    public function __construct(array $commands = array(), OptionsParser $parser = null, TextWriter $writer = null)
    {
        $this->optionsParser = $parser ?: new DefaultOptionsParser();
        $this->textWriter = $writer ?: new StdTextWriter();
        if ($this->helpCommandClass) {
            $this->addCommand($this->helpCommandClass, $this->helpCommand);
            $this->addCommands($commands);
        }
    }

    /**
     * @param OptionsParser $parser
     * @return Console
     */
    public function setOptionsParser(OptionsParser $parser)
    {
        $this->optionsParser = $parser;
        return $this;
    }

    /**
     * @return OptionsParser
     */
    public function getOptionsParser()
    {
        return $this->optionsParser;
    }

    /**
     * @param TextWriter $writer
     * @return Console
     */
    public function setTextWriter(TextWriter $writer)
    {
        $this->textWriter = $writer;
        return $this;
    }

    /**
     * @return TextWriter
     */
    public function getTextWriter()
    {
        return $this->textWriter;
    }

    /**
     * Sets whether to call exit(1) when an exception is caught
     *
     * @param bool $exit
     * @return Console
     */
    public function setExitOnException($exit = true)
    {
        $this->exitOnException = $exit;
        return $this;
    }

    /**
     * @return bool
     */
    public function exitsOnException()
    {
        return $this->exitOnException;
    }

    /**
     * Adds multiple commands at once
     *
     * @see addCommand()
     * @param array $commands
     * @return Console
     */
    public function addCommands(array $commands)
    {
        foreach ($commands as $name => $command) {
            $this->addCommand($command, is_numeric($name) ? null : $name);
        }
        return $this;
    }
    
    /**
     * Registers a command
     * 
     * @param callback $callback Associated class name, function name, Command instance or closure
     * @param string $alias Command name to be used in the shell
     * @return Console
     */
    public function addCommand($callback, $alias = null)
    {
        $name = '';
        if (is_string($callback)) {
            $name = $callback;
            if (function_exists($callback)) {
                $name = strtolower(trim(str_replace('_', '-', $name), '-'));
            } else if (class_exists($callback)) {
                if (!is_subclass_of($callback, 'ConsoleKit\Command')) {
                    throw new ConsoleException("'$callback' must be a subclass of 'ConsoleKit\Command'");
                }
                if (substr($name, -7) === 'Command') {
                    $name = substr($name, 0, -7);
                }
                $name = Utils::dashized(basename(str_replace('\\', '/', $name)));
            } else {
                throw new ConsoleException("'$callback' must reference a class or a function");
            }
        } else if (is_object($callback) && !($callback instanceof Closure)) {
            $classname = get_class($callback);
            if (!($callback instanceof Command)) {
                throw new ConsoleException("'$classname' must inherit from 'ConsoleKit\Command'");
            }
            if (substr($classname, -7) === 'Command') {
                $classname = substr($classname, 0, -7);
            }
            $name = Utils::dashized(basename(str_replace('\\', '/', $classname)));
        } else if (!$alias) {
            throw new ConsoleException("Commands using closures must have an alias");
        }

        $this->commands[$alias ?: $name] = $callback;
        return $this;
    }

    /**
     * Registers commands from a directory
     * 
     * @param string $dir
     * @param string $namespace
     * @param bool $includeFiles
     * @return Console
     */
    public function addCommandsFromDir($dir, $namespace = '', $includeFiles = false)
    {
        foreach (new DirectoryIterator($dir) as $file) {
            $filename = $file->getFilename();
            if ($file->isDir() || substr($filename, 0, 1) === '.' || strlen($filename) <= 11 
                || strtolower(substr($filename, -11)) !== 'command.php') {
                    continue;
            }
            if ($includeFiles) {
                include $file->getPathname();
            }
            $className = trim($namespace . '\\' . substr($filename, 0, -4), '\\');
            $this->addCommand($className);
        }
        return $this;
    }

    /**
     * @param string $name
     * @return string
     */
    public function getCommand($name)
    {
        if (!isset($this->commands[$name])) {
            throw new ConsoleException("Command '$name' does not exist");
        }
        return $this->commands[$name];
    }

    /**
     * @return array
     */
    public function getCommands()
    {
        return $this->commands;
    }
    
    /**
     * @param array $args
     * @return mixed Results of the command callback
     */
    public function run(array $argv = null)
    {
        try {
            if ($argv === null) {
                $argv = isset($_SERVER['argv']) ? array_slice($_SERVER['argv'], 1) : array();
            }

            list($args, $options) = $this->getOptionsParser()->parse($argv);
            if (!count($args)) {
                $this->textWriter->writeln(Colors::red("Missing command name"));
                $args[] = $this->helpCommand;
            }

            $command = array_shift($args);
            return $this->execute($command, $args, $options);

        } catch (\Exception $e) {
            $this->writeException($e);
            if ($this->exitOnException) {
                exit(1);
            }
            throw $e;
        }
    }

    /**
     * Executes a command
     *
     * @param string $command
     * @param array $args
     * @param array $options
     * @return mixed
     */
    public function execute($command, array $args = array(), array $options = array())
    {
        if (!isset($this->commands[$command])) {
            throw new ConsoleException("Command '$command' does not exist");
        }
        
        $callback = $this->commands[$command];
        if (is_callable($callback)) {
            return call_user_func($callback, $args, $options, $this);
        }
        $instance = new $callback($this);
        return $instance->execute($args, $options);
    }
    
    /**
     * Writes some text to the text writer
     * 
     * @see TextWriter::write()
     * @param string $text
     * @param array $formatOptions
     * @return Console
     */
    public function write($text, $pipe = TextWriter::STDOUT)
    {
        $this->textWriter->write($text, $pipe);
        return $this;
    }
    
    /**
     * Writes a line of text
     * 
     * @see TextWriter::writeln()
     * @param string $text
     * @param array $formatOptions
     * @return Console
     */
    public function writeln($text = '', $pipe = TextWriter::STDOUT)
    {
        $this->textWriter->writeln($text, $pipe);
        return $this;
    }

    /**
     * Writes an error message to stderr
     *
     * @param \Exception $e
     * @return Console
     */
    public function writeException(\Exception $e)
    {
        $text = sprintf("Uncaught exception '%s' with message '%s' in %s:%s\nStack trace:\n%s", 
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );

        $box = new Widgets\Box($this->textWriter, $text);
        $this->textWriter->writeln(Colors::colorize($box, Colors::RED | Colors::BOLD));
        return $this;
    }
}