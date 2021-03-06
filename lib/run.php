<?php

/**
 * Certify an object, make sure it has executed
 */
function certify(&$scope) {
  if ($scope instanceof Closure) {
    return;
  }
  if (is_object($scope) && isset($scope->{'#type'}) &&
      $scope->{'#type'} !== 'proto' &&
      (!isset($scope->{'#done'}) || !$scope->{'#done'})) {
    $result = run($scope);
    if ($result !== $scope) {
      $scope->{'#certify'} = $result;
      $scope = $result;
    }
  }
  else if (is_object($scope) && isset($scope->{'#certify'})) {
    $scope = $scope->{'#certify'};
  }
}

/**
 * Is Operation?
 */
function is_operation($scope) {
  return is_object($scope) && isset($scope->{'#operator'});
}

/**
 * Construct Operation
 */
function operation($left, $operator) {
  
  /**
   * Triggers are instant operators, no right side needed
   */
  $proto = proto($left);
  if (isset($proto->{"#trigger $operator"})) {
    $fn = $proto->{"#trigger $operator"};
    return $fn($left);
  }
  
  /**
   * Construct the operation
   */
  $op = new stdClass;
  $op->{'#left'} = $left;
  $op->{'#operator'} = $operator;
  return $op;
}

/**
 * Operate - Perform an Operation (left + operator) on right
 */
function operate($op, $right, $context=null) {
  $operator = $op->{'#operator'};
  $left = $op->{'#left'};
  $name = "#operator $operator";
  
  /**
   * Operation is defined on object $left
   */
  if (isset($left->$name)) {
    $actor = $left;
  }
  else {
    $actor = proto($left);
    
    /**
     * Check that operation is defined
     */
    if (!isset($actor->$name)) {
      throw new Exception("Operator $operator not defined for type " . typestr($left));
    }
  }
  
  /**
   * Check for identifiers
   */
  if (isset($right->{'#type'})) {
    switch($right->{'#type'}) {
      case 'value':
        $right = $right->value;
        break;
      case 'identifier':
        /**
         * Special cases, this should be cleaned up
         */
        if ($operator === '.' || $operator === '@' || $operator === '&' ||
          $operator === '::' || $operator === '++' || $operator === '??') {
          $right = $right->value;
        }
        else {
          $right = get($context, $right->value);
        }
        break;
      case 'break':
        throw new Exception("Invalid break found in source");
      default:
        throw new Exception("Invalid source type: " . $right->{'#type'});
    }
  }
  
  /**
   * Special case for groups
   */
  if (is_object($right) && isset($right->{'#type'}) && $right->{'#type'} === 'group') {
    $right = run($right);
  }
  
  /**
   * Do operation
   */
  $fn = $actor->$name;
  return $fn($left, $right, $context);
}

/**
 * Apply - The big bad boy of SimplifiedPHP
 */
function apply($left, $right) {
  
  /**
   * Special case for groups
   */
  $i = 0;
  while (is_object($left) && isset($left->{'#type'}) && $left->{'#type'} === 'group') {
    $left = run($left);
    if ($i++ > 20) {
      throw new Exception("Too many nested groups");
    }
  }
  $i = 0;
  while (is_object($right) && isset($right->{'#type'}) && $right->{'#type'} === 'group') {
    $right = run($right);
    if ($i++ > 20) {
      throw new Exception("Too many nested groups");
    }
  }

  $try = true;
  while(true) {
    $rtype = typestr($right);

    /**
     * If null, return other
     */
    if (is_null($left)) {
      return $right;
    }
    else if (is_null($right)) {
      if (typestr($left) === 'object') {
        return run($left);
      }
      return $left;
    }
    
    $proto = proto($left);
    $specific = "#apply $rtype";
    $generic = "#apply *";

    if ($try && $rtype === 'object' && !isset($left->$generic) && !isset($proto->$generic)
      && !isset($left->$specific) && !isset($proto->$specific)) {
      $right = run($right);
      $try = false;
      continue;
    }
    break;
  }

  if (isset($left->$specific)) {
    $fn = $left->$specific;
  }
  else if (isset($left->$generic)) {
    $fn = $left->$generic;
  }
  else if (isset($proto->$specific)) {
    $fn = $proto->$specific;
  }
  else if (isset($proto->$generic)) {
    $fn = $proto->$generic;
  }
  else {

    $rproto = proto($right);
    if ($rproto && isset($rproto->{'#applied'})) {
      $fn = $rproto->{'#applied'};
      return $fn($left, $right);
    }
    
    $name = is_object($left) && isset($left->{'#name'}) ? " " . $left->{'#name'} : '';
    throw new Exception(typestr($left) . $name . " does not allow application of type $rtype");
  }
  
  return $fn($left, $right);
}

/**
 * Run code
 */
function run($scope, $context=null, $on=null) {

  /**
   * This is a stack to process
   */
  if (is_array($scope)) {
    $register = null;
    foreach($scope as $source) {
      try {
        
        /**
         * Perform operations
         */
        if (is_operation($register)) {
          $register = operate($register, $source, $context);
        }
        
        /**
         * Apply values
         */
        else {
          switch ($source->{'#type'}) {
            case 'value':
              if (is_object($register) && isset($register->{'#done'})) {
                $register->{'#done'} = false;
              }
              if (is_object($source->value) && isset($source->value->{'#done'})) {
                $source->value->{'#done'} = false;
              }
              $register = apply($register, $source->value);
              break;
            case 'identifier':
              $register = apply($register, get($context, $source->value));
              break;
            case 'operator':
              $register = operation($register, $source->value);
              break;
            case 'break':
              throw new Exception("Invalid break found in source");
            default:
              throw new Exception("Invalid source type: " . $source->{'#type'});
          }
        }
      }
      catch(Exception $e) {
        if ($e instanceof InternalException) {
          throw $e;
        }
        $l = $source->line;
        $c = $source->column;
        $m = $e->getMessage();
        $from = $e->getFile() . ':' . $e->getLine();
        throw new Exception("$m at $l:$c (from $from)", 0, $e);
      }
    }
    
    return $register;
  }
  
  /**
   * This is an object to process
   */
  else if (is_object($scope)) {
    /**
     * Track run count
     */
    $scope->{'#runcount'} = (isset($scope->{'#runcount'}) ?
      $scope->{'#runcount'} : 0) + 1;
    $scope->{'#done'} = true;
    if (!is_null($on)) {
      $fn = type::$object->{'#run'};
      return $fn($scope, $on);
    }
    return get($scope, '#run');
  }
  
  /**
   * This is not runnable
   */
  else {
    return $scope;
  }
}
