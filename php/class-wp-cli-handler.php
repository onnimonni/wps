<?php
namespace Rarst\wps;

use Whoops\Exception\Formatter;
use Whoops\Handler\Handler;
use Whoops\Handler\PlainTextHandler;
use Whoops\Util\Misc;
use Whoops\Exception\Frame;

/**
 * WordPress-specific version of Json handler.
 */
class Wp_Cli_Handler extends PlainTextHandler {

    /**
     * Outputs
     */
    public function generateResponse() {
        $exception = $this->getException();
        $exception_class = get_class($exception);

        switch ($exception_class) {

            # Don't output Whoops Exceptions
            case 'Whoops\Exception\ErrorException':
                $output = sprintf("\033[0;31m%s\033[35m in file %s on line %d\033[0m%s\n",
                    $exception->getMessage(),
                    $exception->getFile(),
                    $exception->getLine(),
                    $this->getTraceOutput()
                );
                break;

            default:
                $output = sprintf("\033[0;31m%s: %s\033[35m in file %s on line %d\033[0m%s\n",
                    $exception_class,
                    $exception->getMessage(),
                    $exception->getFile(),
                    $exception->getLine(),
                    $this->getTraceOutput()
                );
                break;
        }

        return $output;
    }

    /**
     * Get the frame args var_dump.
     * @param  \Whoops\Exception\Frame $frame [description]
     * @param  integer                 $line  [description]
     * @return string
     */
    private function getFrameArgsOutput(Frame $frame, $line)
    {
        if ($this->addTraceFunctionArgsToOutput() === false
            || $this->addTraceFunctionArgsToOutput() < $line) {
            return '';
        }

        // Dump the arguments:
        ob_start();
        if (ob_get_length() > $this->getTraceFunctionArgsOutputLimit()) {
            // The argument var_dump is to big.
            // Discarded to limit memory usage.
            ob_clean();
            return sprintf(
                "\n%sArguments dump length greater than %d Bytes. Discarded.",
                self::VAR_DUMP_PREFIX,
                $this->getTraceFunctionArgsOutputLimit()
            );
        }

        return sprintf("\n%s",
            preg_replace('/^/m', self::VAR_DUMP_PREFIX, ob_get_clean())
        );
    }

    /**
     * Get the exception trace as plain text.
     * @return string
     */
    public function getTraceOutput()
    {
        if (! $this->addTraceToOutput()) {
            return '';
        }
        $inspector = $this->getInspector();
        $frames = $inspector->getFrames();



        $response = "\n\033[32mStack trace:\033[0m";

        $line = 1;
        foreach ($frames as $frame) {
            /** @var Frame $frame */
            $pretty_class = $class = $frame->getClass();
            $function = $frame->getFunction();

            $args = self::pretty_print_args( $frame->getArgs() );

            // Skip Whoops classes from the output
            if ( self::skip_stack_trace_internal_class($class) ) { continue; }

            $template = "\n\033[32m%3d. \033[0m";
            if ($class) {

                $pretty_class = self::pretty_print_class($class);

                if( $function ) {
                    $template .= "%s->%s";
                } else {
                    // This is constructor, don't use ->
                    $template .= "\033[0;31mnew\033[0m %s%s";
                }
            } else {
                // Remove method arrow (->) from output.
                $template .= "%s%s";
            }

            // Add args, file and line
            if ( strstr($args, "\n") ) {
                $template .= "(".self::print_depth(7)."%s";
                $template .= self::print_depth(5).")  "."%s:\033[33m%d\033[0m%s";
            } else {
                $template .= "( %s )   %s:\033[33m%d\033[0m%s";
            }

            // Add file and line
            //$template .= "  %s:\033[33m%d\033[0m%s";

            $response .= sprintf(
                $template,
                $line,
                $pretty_class,
                $frame->getFunction(),
                $args,
                $frame->getFile(),
                $frame->getLine(),
                $this->getFrameArgsOutput($frame, $line)
            );

            // Stop the output when all of the WP-Cli classes start for cleaner output
            if ( self::stop_stack_trace_internal_class($class) ) { break; }

            $line++;
        }

        return $response;
    }

    static private function pretty_print_args($arguments, $depth = 3, $options = [] ) {
        // Stop fast
        if ( ! $arguments || empty($arguments) ) { return ''; }

        $pretty_args = array();

        $depth += 4;

        $assoc = self::is_associative_array( $arguments );

        foreach ( $arguments as $key => $arg ) {

            $pretty_key = '';
            if ( $assoc ) {

                if ( isset( $options['parent_class'] ) ) {

                    // These are class variables
                    if ( strpos($key, "\000{$options['parent_class']}\000" ) === 0 ) { // private
                        $pretty_key = "\033[0;31mprivate\033[0m $" . substr($key, strlen( "\000{$options['parent_class']}\000" ) );
                    } elseif ( strpos($key, "\000*\000" ) === 0 ) { // protected
                        $pretty_key = "\033[0;31mprotected\033[0m $" . substr($key, strlen( "\000*\000" ) );
                    } else { // public
                        $pretty_key = "\033[0;31mpublic\033[0m $" . $key;
                    }

                } else {
                    switch ( gettype( $key ) ) {
                    case 'string':
                        $pretty_key = "\033[33m'{$key}'\033[0m";
                        break;
                    case 'integer':
                        $pretty_key = "\033[35m{$key}\033[0m";
                        break;
                    default:
                        break;
                    }
                }
                $pretty_key .= "\033[0;31m => \033[0m";
            }

            // Output basic types
            switch ( gettype($arg) ) {
                case 'string':
                    $pretty_arg = "\033[33m'{$arg}'\033[0m";
                    break;

                case 'boolean':
                case 'integer':
                case 'double':
                    $pretty_arg = "\033[35m{$arg}\033[0m";
                    break;

                case 'array':
                    $element = "\n".str_repeat(' ', $depth)."\033[34marray\033[0m(";
                    if (empty($arg)) {
                        $element .= ")";
                    } else {
                        $element .= self::pretty_print_args($arg, $depth).self::print_depth($depth).")";
                    }
                    $pretty_arg = $element;
                    break;
                case 'object':
                    $pretty_arg = self::pretty_print_object( $arg, $depth );
                    break;
                default:
                    $pretty_arg = "\033[34m".gettype($arg)."\033[0m";
                    break;
            }

            $pretty_args[] = $pretty_key.$pretty_arg;
        }

        $output = "\n";

        // Add padding into string
        if ( count($pretty_args) >= 3 ) {

            $pretty_args = array_map( function($a) use ( $depth ) {
                return str_repeat(' ', $depth).$a;
            }, $pretty_args );

            $output .= implode(",\n", $pretty_args);
        } else {
            $output = implode(",", $pretty_args);
        }

        return $output;
    }

    static private function is_associative_array(array $arr) {
        if (array() === $arr) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    static private function pretty_print_class($class) {
        // Stop fast
        $namespaces_split = explode( '\\', $class );
        end($namespaces_split);
        $key = key($namespaces_split);

        reset($namespaces_split);

        $namespaces_split[$key] = "\033[34m".$namespaces_split[$key]."\033[0m";

        return implode('\\',$namespaces_split);
    }

    static private function pretty_print_object( $object, $depth ) {
        // Stop fast
        $class = get_class($object);

        $class_variables =  (array)$object;
        $output = '';
        $output .= self::pretty_print_class($class) . '(';
        if ( ! empty($class_variables) ) {
            $output .= self::pretty_print_args( $class_variables, $depth, [ 'parent_class' => $class ] );
            if ( count($class_variables) >= 3 ) {
                $output .= self::print_depth($depth);
            }
        }

        $output .= ')';

        return $output;
    }

    static private function print_depth($depth) {
        return "\n". str_repeat(' ', $depth);
    }

    static private function pretty_print_string(string $string) {
        return "\033[33m'{$string}'\033[0m";
    }

    static private function pretty_print_number($number) {
        return "\033[35m{$number}\033[0m";
    }

    /**
     * Skip output for Whoops and WP-cli internal classes for cleaner output
     */
    static private function skip_stack_trace_internal_class($class_name) {

        // Skip these for stack trace
        static $skip_classes = array(
            // Whoops classes
            'Whoops\Exception\ErrorException' => true,
            'Whoops\Run' => true
        );

        if ( isset( $skip_classes[ $class_name ] ) ) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Stop output for Whoops when first WP-cli internal clas comes
     */
    static private function stop_stack_trace_internal_class($class_name) {

        // Skip these for stack trace
        static $stop_classes = array(
            // WP-cli classes
            'WP_CLI\Runner' => true,
            'WP_CLI\Dispatcher\CommandFactory' => true,
            'WP_CLI\Dispatcher\Subcommand' => true
        );

        if ( isset( $stop_classes[ $class_name ] ) ) {
            return true;
        } else {
            return false;
        }
    }
}
