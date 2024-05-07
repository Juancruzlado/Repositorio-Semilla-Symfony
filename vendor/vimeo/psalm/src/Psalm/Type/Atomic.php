<?php
namespace Psalm\Type;

use Psalm\Type;
use Psalm\Type\Atomic\ObjectLike;
use Psalm\Type\Atomic\Scalar;
use Psalm\Type\Atomic\TNumeric;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TVoid;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TEmpty;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TObject;
use Psalm\Type\Atomic\TResource;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNumericString;
use Psalm\Type\Union;
use Psalm\Checker\ClassChecker;
use Psalm\Checker\ClassLikeChecker;
use Psalm\Checker\FileChecker;
use Psalm\CodeLocation;
use Psalm\StatementsSource;

abstract class Atomic extends Type
{
    const KEY = 'atomic';

    /**
     * @param  string $value
     * @return Atomic
     */
    public static function create($value)
    {
        switch ($value) {
            case 'numeric':
                return new TNumeric();

            case 'int':
                return new TInt();

            case 'void':
                return new TVoid();

            case 'float':
                return new TFloat();

            case 'string':
                return new TString();

            case 'bool':
            case 'true':
                return new TBool();

            case 'false':
                return new TFalse();

            case 'empty':
                return new TEmpty();

            case 'null':
                return new TNull();

            case 'array':
                return new TArray([new Union([new TMixed]), new Union([new TMixed])]);

            case 'object':
                return new TObject();

            case 'mixed':
                return new TMixed();

            case 'resource':
                return new TResource();

            case 'callable':
                return new TCallable();

            case 'numeric-string':
                return new TNumericString();

            default:
                return new TNamedObject($value);
        }
    }

    /**
     * @param   Union        $parent
     * @param   FileChecker  $file_checker
     * @return  bool
     */
    public function isIn(Union $parent, FileChecker $file_checker)
    {
        if ($parent->isMixed()) {
            return true;
        }

        if ($parent->hasType('object') &&
            $this instanceof TNamedObject &&
            ClassLikeChecker::classOrInterfaceExists($this->value, $file_checker)
        ) {
            return true;
        }

        if ($parent->hasType('numeric') && $this->isNumericType()) {
            return true;
        }

        if ($parent->hasType('array') && $this instanceof ObjectLike) {
            return true;
        }

        if ($this instanceof TFalse && $parent->hasType('bool')) {
            // this is fine
            return true;
        }

        if ($parent->hasType($this->getKey())) {
            return true;
        }

        // last check to see if class is subclass
        if ($this instanceof TNamedObject && ClassChecker::classExists($this->value, $file_checker)) {
            $this_is_subclass = false;

            foreach ($parent->types as $parent_type) {
                if ($parent_type instanceof TNamedObject &&
                    ClassChecker::classExtendsOrImplements($this->value, $parent_type->value)
                ) {
                    $this_is_subclass = true;
                    break;
                }
            }

            if ($this_is_subclass) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string
     */
    abstract public function getKey();

    /**
     * @return bool
     */
    public function isNumericType()
    {
        return $this instanceof TInt || $this instanceof TFloat || $this instanceof TNumericString;
    }

    /**
     * @return bool
     */
    public function isObjectType()
    {
        return $this instanceof TObject || $this instanceof TNamedObject;
    }

    /**
     * @param  StatementsSource $source
     * @param  CodeLocation     $code_location
     * @param  array<string>    $suppressed_issues
     * @return false|null
     */
    public function check(StatementsSource $source, CodeLocation $code_location, array $suppressed_issues)
    {
        if ($this instanceof TNamedObject
            && ClassLikeChecker::checkFullyQualifiedClassLikeName(
                $this->value,
                $source->getFileChecker(),
                $code_location,
                $suppressed_issues
            ) === false
        ) {
            return false;
        }

        if ($this instanceof Type\Atomic\TArray || $this instanceof Type\Atomic\TGenericObject) {
            foreach ($this->type_params as $type_param) {
                $type_param->check($source, $code_location, $suppressed_issues);
            }
        }
    }

    /**
     * @param  Atomic $other
     * @return bool
     */
    public function shallowEquals(Atomic $other)
    {
        return $this->getKey() === $other->getKey();
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return '';
    }

    /**
     * @param  array<string> $aliased_classes
     * @param  string|null   $this_class
     * @param  bool          $use_phpdoc_format
     * @return string
     */
    public function toNamespacedString(array $aliased_classes, $this_class, $use_phpdoc_format)
    {
        return $this->getKey();
    }
}
