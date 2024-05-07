<?php
namespace Psalm\Checker;

use PhpParser;
use Psalm\CodeLocation;
use Psalm\Config;
use Psalm\EffectsAnalyser;
use Psalm\Exception\DocblockParseException;
use Psalm\FunctionLikeParameter;
use Psalm\Issue\DuplicateParam;
use Psalm\Issue\InvalidDocblock;
use Psalm\Issue\InvalidReturnType;
use Psalm\IssueBuffer;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Type;

class FunctionChecker extends FunctionLikeChecker
{
    /**
     * @var PhpParser\Node\Stmt\Function_
     */
    protected $function;

    /**
     * @var array<array<string,string>>|null
     */
    protected static $call_map = null;

    /**
     * @var array<string, FunctionLikeStorage>
     */
    protected static $builtin_functions = [];

    /**
     * @param mixed                         $function
     * @param StatementsSource              $source
     */
    public function __construct($function, StatementsSource $source)
    {
        if (!$function instanceof PhpParser\Node\Stmt\Function_) {
            throw new \InvalidArgumentException('Bad');
        }

        parent::__construct($function, $source);

        self::register($function, $this);
    }

    /**
     * @param  string $function_id
     * @param  string $file_path
     * @return boolean
     */
    public static function functionExists($function_id, $file_path)
    {
        if (isset(FileChecker::$storage[$file_path]->functions[$function_id])) {
            return true;
        }

        if (strpos($function_id, '::') !== false) {
            $function_id = strtolower(preg_replace('/^[^:]+::/', '', $function_id));
        }

        if (isset(self::$builtin_functions[$function_id])) {
            return true;
        }

        if (self::extractReflectionInfo($function_id) === false) {
            return false;
        }

        return true;
    }

    /**
     * @param  string $function_id
     * @param  string $file_path
     * @return array<int, FunctionLikeParameter>
     */
    public static function getParams($function_id, $file_path)
    {
        if (isset(self::$builtin_functions[$function_id]) && self::$builtin_functions[$function_id]) {
            return self::$builtin_functions[$function_id]->params;
        }

        $file_storage = FileChecker::$storage[$file_path];

        if (!isset($file_storage->functions[$function_id])) {
            throw new \UnexpectedValueException('Not expecting ' . $function_id . ' to not have storage in ' . $file_path);
        }

        return $file_storage->functions[$function_id]->params;
    }

    /**
     * @param  string $function_id
     * @param  string $file_path
     * @return array<string, Type\Union>
     */
    public static function getDefinedConstants($function_id, $file_path)
    {
        $file_storage = FileChecker::$storage[$file_path];

        if (!isset($file_storage->functions[$function_id])) {
            return [];
        }

        return $file_storage->functions[$function_id]->defined_constants;
    }

    /**
     * @param  string $function_id
     * @param  string $file_path
     * @return boolean
     */
    public static function isVariadic($function_id, $file_path)
    {
        $file_storage = FileChecker::$storage[$file_path];

        return isset($file_storage->functions[$function_id]) && $file_storage->functions[$function_id]->variadic;
    }

    /**
     * @param  string $function_id
     * @return false|null
     */
    protected static function extractReflectionInfo($function_id)
    {
        try {
            $reflection_function = new \ReflectionFunction($function_id);

            $storage = self::$builtin_functions[$function_id] = new FunctionLikeStorage();

            $reflection_params = $reflection_function->getParameters();

            /** @var \ReflectionParameter $param */
            foreach ($reflection_params as $param) {
                $param_obj = self::getReflectionParamData($param);
                $storage->params[] = $param_obj;
            }

            $storage->cased_name = $reflection_function->getName();
        } catch (\ReflectionException $e) {
            return false;
        }
    }

    /**
     * @param  string $function_id
     * @param  string $file_path
     * @return Type\Union|null
     */
    public static function getFunctionReturnType($function_id, $file_path)
    {
        if (!isset(FileChecker::$storage[$file_path])) {
            return null;
        }

        $file_storage = FileChecker::$storage[$file_path];

        if (!isset($file_storage->functions[$function_id])) {
            throw new \InvalidArgumentException('Do not know function ' . $function_id . ' in file ' . $file_path);
        }

        $function_return_type = $file_storage->functions[$function_id]->return_type;

        return $function_return_type ? clone $function_return_type : null;
    }

    /**
     * @param  string $function_id
     * @param  string $file_path
     * @return CodeLocation|null
     */
    public static function getFunctionReturnTypeLocation($function_id, $file_path)
    {
        $file_storage = FileChecker::$storage[$file_path];

        if (!isset($file_storage->functions[$function_id])) {
            throw new \InvalidArgumentException('Do not know function ' . $function_id . ' in file ' . $file_path);
        }

        return $file_storage->functions[$function_id]->return_type_location;
    }

    /**
     * @param  string $function_id
     * @param  string $file_path
     * @return string
     */
    public static function getCasedFunctionId($function_id, $file_path)
    {
        $file_storage = FileChecker::$storage[$file_path];

        if (!isset($file_storage->functions[$function_id])) {
            throw new \InvalidArgumentException('Do not know function ' . $function_id . ' in file ' . $file_path);
        }

        return $file_storage->functions[$function_id]->cased_name;
    }

    /**
     * @param  string $function_id
     * @return array|null
     * @psalm-return array<int, array<int, FunctionLikeParameter>>|null
     */
    public static function getParamsFromCallMap($function_id)
    {
        $call_map = self::getCallMap();

        $call_map_key = strtolower($function_id);

        if (!isset($call_map[$call_map_key])) {
            return null;
        }

        $call_map_functions = [];
        $call_map_functions[] = $call_map[$call_map_key];

        for ($i = 1; $i < 10; $i++) {
            if (!isset($call_map[$call_map_key . '\'' . $i])) {
                break;
            }

            $call_map_functions[] = $call_map[$call_map_key . '\'' . $i];
        }

        $function_type_options = [];

        foreach ($call_map_functions as $call_map_function_args) {
            array_shift($call_map_function_args);

            $function_types = [];

            foreach ($call_map_function_args as $arg_name => $arg_type) {
                $by_reference = false;
                $optional = false;
                $variadic = false;

                if ($arg_name[0] === '&') {
                    $arg_name = substr($arg_name, 1);
                    $by_reference = true;
                }

                if (substr($arg_name, -1) === '=') {
                    $arg_name = substr($arg_name, 0, -1);
                    $optional = true;
                }

                if (substr($arg_name, 0, 3) === '...') {
                    $arg_name = substr($arg_name, 3);
                    $variadic = true;
                }

                $function_types[] = new FunctionLikeParameter(
                    $arg_name,
                    $by_reference,
                    $arg_type ? Type::parseString($arg_type) : Type::getMixed(),
                    null,
                    $optional,
                    false,
                    $variadic
                );
            }

            $function_type_options[] = $function_types;
        }

        return $function_type_options;
    }

    /**
     * @param  string  $function_id
     * @return Type\Union
     */
    public static function getReturnTypeFromCallMap($function_id)
    {
        $call_map_key = strtolower($function_id);

        $call_map = self::getCallMap();

        if (!isset($call_map[$call_map_key])) {
            throw new \InvalidArgumentException('Function ' . $function_id . ' was not found in callmap');
        }

        if (!$call_map[$call_map_key][0]) {
            return Type::getMixed();
        }

        return Type::parseString($call_map[$call_map_key][0]);
    }

    /**
     * @param  string                      $function_id
     * @param  array<PhpParser\Node\Arg>   $call_args
     * @param  CodeLocation                $code_location
     * @param  array                       $suppressed_issues
     * @return Type\Union
     */
    public static function getReturnTypeFromCallMapWithArgs(
        $function_id,
        array $call_args,
        CodeLocation $code_location,
        array $suppressed_issues
    ) {
        $call_map_key = strtolower($function_id);

        $call_map = self::getCallMap();

        if (!isset($call_map[$call_map_key])) {
            throw new \InvalidArgumentException('Function ' . $function_id . ' was not found in callmap');
        }

        if ($call_args) {
            if (in_array($call_map_key, ['str_replace', 'preg_replace', 'preg_replace_callback'])) {
                if (isset($call_args[2]->value->inferredType)) {

                    /** @var Type\Union */
                    $subject_type = $call_args[2]->value->inferredType;

                    if (!$subject_type->hasString() && $subject_type->hasArray()) {
                        return Type::getArray();
                    }

                    return Type::getString();
                }
            }

            if (in_array($call_map_key, ['pathinfo'])) {
                if (isset($call_args[1])) {
                    return Type::getString();
                }

                return Type::getArray();
            }

            if (substr($call_map_key, 0, 6) === 'array_') {
                $array_return_type = self::getArrayReturnType(
                    $call_map_key,
                    $call_args,
                    $code_location,
                    $suppressed_issues
                );

                if ($array_return_type) {
                    return $array_return_type;
                }
            }

            if ($call_map_key === 'explode' || $call_map_key === 'preg_split') {
                return Type::parseString('array<int, string>');
            }

            if ($call_map_key === 'min' || $call_map_key === 'max') {
                $minmax_return_type = null;

                if (isset($call_args[0])) {
                    $first_arg = $call_args[0]->value;

                    if (isset($first_arg->inferredType)) {
                        if ($first_arg->inferredType->hasArray()) {
                            $array_type = $first_arg->inferredType->types['array'];
                            if ($array_type instanceof Type\Atomic\ObjectLike) {
                                return $array_type->getGenericTypeParam();
                            }

                            if ($array_type instanceof Type\Atomic\TArray) {
                                return clone $array_type->type_params[1];
                            }
                        } elseif ($first_arg->inferredType->hasScalarType() &&
                            ($second_arg = $call_args[1]->value) &&
                            isset($second_arg->inferredType) &&
                            $second_arg->inferredType->hasScalarType()
                        ) {
                            return Type::combineUnionTypes($first_arg->inferredType, $second_arg->inferredType);
                        }
                    }
                }
            }
        }

        if (!$call_map[$call_map_key][0]) {
            return Type::getMixed();
        }

        return Type::parseString($call_map[$call_map_key][0]);
    }

    /**
     * @param  string                       $call_map_key
     * @param  array<PhpParser\Node\Arg>    $call_args
     * @param  CodeLocation                 $code_location
     * @param  array                        $suppressed_issues
     * @return Type\Union|null
     */
    protected static function getArrayReturnType(
        $call_map_key,
        $call_args,
        CodeLocation $code_location,
        array $suppressed_issues
    ) {
        if ($call_map_key === 'array_map') {
            return self::getArrayMapReturnType($call_map_key, $call_args, $code_location, $suppressed_issues);
        }

        $first_arg = isset($call_args[0]->value) ? $call_args[0]->value : null;
        $second_arg = isset($call_args[1]->value) ? $call_args[1]->value : null;

        $first_arg_array_generic = $first_arg
                && isset($first_arg->inferredType)
                && isset($first_arg->inferredType->types['array'])
                && $first_arg->inferredType->types['array'] instanceof Type\Atomic\TArray
            ? $first_arg->inferredType->types['array']
            : null;

        $first_arg_array_objectlike = $first_arg
                && isset($first_arg->inferredType)
                && isset($first_arg->inferredType->types['array'])
                && $first_arg->inferredType->types['array'] instanceof Type\Atomic\ObjectLike
            ? $first_arg->inferredType->types['array']
            : null;

        if ($call_map_key === 'array_values' || $call_map_key === 'array_unique' || $call_map_key === 'array_intersect') {
            if ($first_arg_array_generic || $first_arg_array_objectlike) {
                if ($first_arg_array_generic) {
                    $inner_type = clone $first_arg_array_generic->type_params[1];
                } else {
                    /** @var Type\Atomic\ObjectLike $first_arg_array_objectlike */
                    $inner_type = $first_arg_array_objectlike->getGenericTypeParam();
                }

                return new Type\Union([
                    new Type\Atomic\TArray([
                        Type::getInt(),
                        $inner_type
                    ])
                ]);
            }
        }

        if ($call_map_key === 'array_keys') {
            if ($first_arg_array_generic || $first_arg_array_objectlike) {
                if ($first_arg_array_generic) {
                    $inner_type = clone $first_arg_array_generic->type_params[0];
                } else {
                    $inner_type = Type::getString();
                }

                return new Type\Union([
                    new Type\Atomic\TArray([
                        Type::getInt(),
                        $inner_type
                    ])
                ]);
            }
        }

        if ($call_map_key === 'array_merge') {
            $inner_value_types = [];
            $inner_key_types = [];

            foreach ($call_args as $offset => $call_arg) {
                if (!isset($call_arg->value->inferredType)) {
                    return Type::getArray();
                }

                foreach ($call_arg->value->inferredType->types as $type_part) {
                    if (!$type_part instanceof Type\Atomic\TArray) {
                        return Type::getArray();
                    }

                    if ($type_part->type_params[1]->isEmpty()) {
                        continue;
                    }

                    $inner_key_types = array_merge(array_values($type_part->type_params[0]->types), $inner_key_types);
                    $inner_value_types = array_merge(
                        array_values($type_part->type_params[1]->types),
                        $inner_value_types
                    );
                }
            }

            if ($inner_value_types) {
                return new Type\Union([
                    new Type\Atomic\TArray([
                        Type::combineTypes($inner_key_types),
                        Type::combineTypes($inner_value_types)
                    ])
                ]);
            }

            return Type::getArray();
        }

        if ($call_map_key === 'array_combine') {
            $second_arg_array_generic = $second_arg
                    && isset($second_arg->inferredType)
                    && isset($second_arg->inferredType->types['array'])
                    && $second_arg->inferredType->types['array'] instanceof Type\Atomic\TArray
                ? $second_arg->inferredType->types['array']
                : null;

            $second_arg_array_objectlike = $second_arg
                    && isset($second_arg->inferredType)
                    && isset($second_arg->inferredType->types['array'])
                    && $second_arg->inferredType->types['array'] instanceof Type\Atomic\ObjectLike
                ? $second_arg->inferredType->types['array']
                : null;

            if ($second_arg_array_generic || $second_arg_array_objectlike) {
                if ($first_arg && isset($first_arg->inferredType) && $first_arg->inferredType->hasArray()) {
                    if ($first_arg_array_generic) {
                        $keys_inner_type = clone $first_arg_array_generic->type_params[1];
                    } else {
                        /** @var Type\Atomic\ObjectLike $first_arg_array_objectlike */
                        $keys_inner_type = $first_arg_array_objectlike->getGenericTypeParam();
                    }
                } else {
                    $keys_inner_type = Type::getMixed();
                }

                if ($second_arg_array_generic) {
                    $values_inner_type = clone $second_arg_array_generic->type_params[1];
                } else {
                    /** @var Type\Atomic\ObjectLike $second_arg_array_objectlike */
                    $values_inner_type = $second_arg_array_objectlike->getGenericTypeParam();
                }

                return new Type\Union([
                    new Type\Atomic\TArray([
                        $keys_inner_type,
                        $values_inner_type
                    ])
                ]);
            }
        }

        if ($call_map_key === 'array_diff') {
            if (!$first_arg_array_generic) {
                return Type::getArray();
            }

            return new Type\Union([
                new Type\Atomic\TArray([
                    Type::getInt(),
                    clone $first_arg_array_generic->type_params[1]
                ])
            ]);
        }

        if ($call_map_key === 'array_filter') {
            if (!$first_arg_array_generic) {
                return Type::getArray();
            }

            $second_arg = isset($call_args[1]->value) ? $call_args[1]->value : null;

            $inner_type = clone $first_arg_array_generic->type_params[1];

            if (!$second_arg) {
                $inner_type->removeType('null');
                $inner_type->removeType('false');
            }

            return new Type\Union([
                new Type\Atomic\TArray([
                    clone $first_arg_array_generic->type_params[0],
                    $inner_type
                ])
            ]);
        }

        if ($call_map_key === 'array_diff_key') {
            if (!$first_arg_array_generic) {
                return Type::getArray();
            }

            return clone $first_arg->inferredType;
        }

        if ($call_map_key === 'array_shift' || $call_map_key === 'array_pop') {
            if (!$first_arg_array_generic) {
                return Type::getMixed();
            }

            return clone $first_arg_array_generic->type_params[1];
        }

        return null;
    }

    /**
     * @param  string                       $call_map_key
     * @param  array<PhpParser\Node\Arg>    $call_args
     * @param  CodeLocation                 $code_location
     * @param  array                        $suppressed_issues
     * @return Type\Union
     */
    protected static function getArrayMapReturnType(
        $call_map_key,
        $call_args,
        CodeLocation $code_location,
        array $suppressed_issues
    ) {
        $function_index = $call_map_key === 'array_map' ? 0 : 1;
        $array_index = $call_map_key === 'array_map' ? 1 : 0;

        $array_arg = isset($call_args[$array_index]->value) ? $call_args[$array_index]->value : null;

        $array_arg_type = $array_arg
                && isset($array_arg->inferredType)
                && isset($array_arg->inferredType->types['array'])
                && $array_arg->inferredType->types['array'] instanceof Type\Atomic\TArray
            ? $array_arg->inferredType->types['array']
            : null;

        if (isset($call_args[$function_index])) {
            $function_call_arg = $call_args[$function_index];

            if ($function_call_arg->value instanceof PhpParser\Node\Expr\Closure &&
                isset($function_call_arg->value->inferredType) &&
                $function_call_arg->value->inferredType->types['Closure'] instanceof Type\Atomic\Fn
            ) {
                $closure_return_type = $function_call_arg->value->inferredType->types['Closure']->return_type;

                if ($closure_return_type->isVoid()) {
                    IssueBuffer::accepts(
                        new InvalidReturnType(
                            'No return type could be found in the closure passed to ' . $call_map_key,
                            $code_location
                        ),
                        $suppressed_issues
                    );

                    return Type::getArray();
                }

                $key_type = $array_arg_type ? clone $array_arg_type->type_params[0] : Type::getMixed();

                if ($call_map_key === 'array_map') {
                    $inner_type = clone $closure_return_type;
                    return new Type\Union([
                        new Type\Atomic\TArray([
                            $key_type,
                            $inner_type
                        ])
                    ]);
                }

                if ($array_arg_type) {
                    $inner_type = clone $array_arg_type->type_params[1];
                    return new Type\Union([
                        new Type\Atomic\TArray([
                            $key_type,
                            $inner_type
                        ])
                    ]);
                }
            } elseif ($function_call_arg->value instanceof PhpParser\Node\Scalar\String_) {
                $mapped_function_id = strtolower($function_call_arg->value->value);

                $call_map = self::getCallMap();

                if (isset($call_map[$mapped_function_id][0])) {
                    if ($call_map[$mapped_function_id][0]) {
                        $mapped_function_return = Type::parseString($call_map[$mapped_function_id][0]);
                        return new Type\Union([
                            new Type\Atomic\TArray([
                                Type::getInt(),
                                $mapped_function_return
                            ])
                        ]);
                    }
                } else {
                    // @todo handle array_map('some_custom_function', $arr)
                }
            }
        }

        return Type::getArray();
    }

    /**
     * Gets the method/function call map
     *
     * @return array<string, array<string, string>>
     * @psalm-suppress MixedInferredReturnType as the use of require buggers things up
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MoreSpecificReturnType
     */
    protected static function getCallMap()
    {
        if (self::$call_map !== null) {
            return self::$call_map;
        }

        /** @var array<string, array<string, string>> */
        $call_map = require_once(__DIR__.'/../CallMap.php');

        self::$call_map = [];

        foreach ($call_map as $key => $value) {
            $cased_key = strtolower($key);
            self::$call_map[$cased_key] = $value;
        }

        return self::$call_map;
    }

    /**
     * @param   string $key
     * @return  bool
     */
    public static function inCallMap($key)
    {
        return isset(self::getCallMap()[strtolower($key)]);
    }

    /**
     * @param  string                   $function_name
     * @param  StatementsSource         $source
     * @return string
     */
    public static function getFQFunctionNameFromString($function_name, StatementsSource $source)
    {
        if (empty($function_name)) {
            throw new \InvalidArgumentException('$function_name cannot be empty');
        }

        if ($function_name[0] === '\\') {
            return substr($function_name, 1);
        }

        $imported_function_namespaces = $source->getAliasedFunctions();
        $imported_namespaces = $source->getAliasedClasses();

        if (strpos($function_name, '\\') !== false) {
            $function_name_parts = explode('\\', $function_name);
            $first_namespace = array_shift($function_name_parts);

            if (isset($imported_namespaces[strtolower($first_namespace)])) {
                return $imported_namespaces[strtolower($first_namespace)] . '\\' . implode('\\', $function_name_parts);
            }

            if (isset($imported_function_namespaces[strtolower($first_namespace)])) {
                return $imported_function_namespaces[strtolower($first_namespace)] . '\\' . implode('\\', $function_name_parts);
            }
        } elseif (isset($imported_namespaces[strtolower($function_name)])) {
            return $imported_namespaces[strtolower($function_name)];
        } elseif (isset($imported_function_namespaces[strtolower($function_name)])) {
            return $imported_function_namespaces[strtolower($function_name)];
        }

        $namespace = $source->getNamespace();

        return ($namespace ? $namespace . '\\' : '') . $function_name;
    }

    /**
     * @return void
     */
    public static function clearCache()
    {
        self::$builtin_functions = [];
    }
}
