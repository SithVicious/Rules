<?php

/**
 * PHP simple rule engine
 *
 * @author tonera
 */
class Rules {

    public $object = array();  //object array
    public $rules = array();
    public $data = array();  //source data
    public $mapData = array();  //map data based on rules
    public $rulesMap = array();

    const E_KEYEXISTS = '41000';
    const E_NOTARRAY = '41001';
    const E_RULENAME = '41002';
    const E_RULENAMENOTEXISTS = '41003';
    const E_VALUEFORMAT = '41004';
    const E_OPFORMAT = '41005';
    const E_OPERATOR = '41006';

    private $errorsCode = array(
        '41000' => 'Data\'s key is not exists',
        '41001' => 'Value is not an array',
        '41002' => 'Rule file format error:Rule name is not difined',
        '41003' => 'Rule name is not exists',
        '41004' => 'Value\'s format is wrong',
        '41005' => 'Error operator',
        '41006' => 'Operator is wrong.The right operator are:== != > < >= <= , memberof , contains , not memberof , not contains',
    );
    public $errors = array();

    public function initRulesMap($ruleFile) {
        $lines = file($ruleFile);
        $currentRuleName = "";
        $type = "";
        $i = 0;
        $lastType = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if(empty($line)){
                continue;
            }
            //跳过注释
	        //Skip comment
            $firstLetter = substr($line, 0, 1);
            if ($firstLetter == '/' or $firstLetter == '#') {
                continue;
            }
            $commands = explode(' ', $line);
            if ($commands[0] == 'rule') {
                if (!isset($commands[1])) {
                    throw new Exception($ruleFile . ':' . $this->errorsCode[self::E_RULENAME], self::E_RULENAME);
                }
                $currentRuleName = trim($commands[1]);
                $this->rulesMap[$currentRuleName][$i] = array();
            }
	        //当前 $currentRuleName 为空时,跳过后续的语法
	        //When the current $currentRuleName is empty, skip the subsequent syntax
            if ($commands[0] == 'when' and $currentRuleName) {
                //Mark subsequent statements as regular statements
                $type = "when";
                //There can be multiple when-then nesting in a rule
                if ($lastType == 'then') {
                    $i++;
                }
                continue;
            }
	        //标记后续语句为 then
	        //Mark the subsequent statement as then
            if ($commands[0] == 'then' and $currentRuleName) {
                //标记后续语句为规则语句
                $type = "then";
                continue;
            }
	        //end表明此规则结束
            //end indicates the end of this rule
            if ($commands[0] == 'end' and $currentRuleName) {
	            //标记后续语句为规则语句
	            //Mark subsequent statements as regular statements
                $type = '';
                $lastType = '';
                $currentRuleName = '';
                $i = 0;
                continue;
            }
            if ($type == 'when' and $currentRuleName) {
                $this->rulesMap[$currentRuleName][$i]['when'][] = trim($line);
            }
            if ($type == 'then' and $currentRuleName) {
                $this->rulesMap[$currentRuleName][$i]['then'][] = trim($line);
                $lastType = 'then';
            }
        }
	    //将map进一步拆解为条件
        //Disassemble the map further into conditions
        foreach ($this->rulesMap as $ruleName => $actions) {
            foreach ($actions as $idx => $act) {
                $ruleString = implode(';', $act['when']);
                $this->rules[$ruleName][$idx] = $this->_parse($ruleString);
            }
        }
        return $this->rules;
    }

    public function resetRulesMap() {
        $this->rulesMap = array();
    }

    public function import(& $data) {
        $this->data = $data;
    }

    public function execute($ruleName) {
        if (!isset($this->rules[$ruleName])) {
            throw new Exception($ruleName . ':' . $this->errorsCode[self::E_RULENAMENOTEXISTS], self::E_RULENAMENOTEXISTS);
        }
        $rulesList = $this->rules[$ruleName];

        foreach ($rulesList as $idx => $rules) {
            $flag = FALSE;
            foreach ($rules as $object => $types) {

                //$method="user:card";
                $objects = explode(':', $object);
                $this->mapData[$object] = $this->getMapValue($objects, $this->data);

                //start to match of or
                foreach ($types['or'] as $rule) {
                    if ($this->counting($object, $rule)) {
                        $flag = TRUE;
                        break;
                    }
                }

                foreach ($types['and'] as $rule) {
                    if (!$this->counting($object, $rule)) {
                        $flag = FALSE;
                        break;
                    } else {
                        $flag = TRUE;
                    }
                }
	            //每规则间是and关系,所以只要有一个false,则全部false
                //Each rule is an and relationship, so as long as there is one false, all are false
                if (!$flag) {
                    break;
                }
            }

            if ($flag) {
                $this->_action($ruleName, $idx);
            }
        }
    }

    private function _action($ruleName, $idx) {
        $thenString = implode(';', $this->rulesMap[$ruleName][$idx]['then']);
        $arr = explode(';', $thenString);

        $matchs = array();
        foreach ($arr as $value) {
            if (empty($value)) {
                continue;
            }
            //var_dump($value);
            $actionString = trim($value);
            preg_match_all("#\\\$([\w|:]+)([\s(=]+)(.+)#i", $value, $matchs, PREG_SET_ORDER);
            //var_dump($matchs);
	        //三种情况:直接将一个对象赋值;将对象的一个属性赋值;调用用户自定义函数
            //Three cases: directly assign an object; assign an attribute of the object; call a user-defined function
            $fourLetters = substr($actionString, 0, 4);
            if ($fourLetters == 'call' or $fourLetters == 'CALL') {
	            //自定义函数
                //Custom function
                $params = array();
                preg_match_all("#call\(\s*(.+)\s*\)#i", $actionString, $params, PREG_SET_ORDER);
                $list = explode(',', $params[0][1]);
                $methodParams = array_slice($list, 1);
	            //$methodParams 有变量的,需要计算出变量值
                //$methodParams has variables, you need to calculate the value of the variable
                if (stristr($list[0], '->') !== false) {
                    $funcArr = explode('->', $list[0]);
                    $className = $funcArr[0];
                    $method = $funcArr[1];
                    $class = $this->getMapValue(array($className), $this->data);
                    foreach ($methodParams as $pk => $pv) {
                        $firstLetter = substr(trim($pv), 0, 1);
                        if ($firstLetter == '$') {
                            $var = substr(trim($pv), 1);
                            $methodParams[$pk] = $this->getMapValue(explode(':', $var), $this->data);
                        }
                    }

                    $class->$method($methodParams);
                } else {
                    $method = $list[0];
                    $$method($methodParams);
                }
                //var_dump($methodParams);
            } elseif (isset($matchs[0][2]) and trim($matchs[0][2]) == '(') {
	            //属性赋值
                //Attribute assignment
                $keysPath = explode(':', $matchs[0][1]);
                foreach ($keysPath as $i => $key) {
                    if ($i == 0)
                        $rs = & $this->findArrByKey($key, $this->data);
                    else
                        $rs = & $this->findArrByKey($key, $rs);
                }
                $vars = explode(',', preg_replace("/\)$/i", '', $matchs[0][3]));

                $updateData = array();
                foreach ($vars as $value) {
                    $kv = explode('=', $value);
                    $kv[0] = trim($kv[0]);
                    $updateData[$kv[0]] = $this->getTureValue($kv[1]);
                }

                foreach ($updateData as $key => $value) {
                    $rs[$key] = $value;
                }
            } else {
                //对象赋值
                //Object assignment
                $key = trim($matchs[0][1]);
                $value = trim($matchs[0][3]);
                if (substr($value, 0, 1) == '$') {
                    //$value = $this->getMapValue(explode(':', substr($value, 1)), $this->data);
                    $value = $this->getTureValue($value,$this->data);
                }
                $this->data[$key] = $value;
            }
        }
    }

    //判断一个值字符串是否需要进行运算(当值字符串中含有数学运算符时且含有变量时),如果是,返回运算后结果,否则返回原值
	//Determine whether a value string needs to be operated (when the value string contains mathematical operators and variables),
	// if yes, return the result of the operation, otherwise return the original value
    private function getTureValue($valueString) {
        $valueString = trim($valueString);
        if (stristr($valueString, '$') === FALSE) {
            return $valueString;
        } else {
            $arr = array();
            preg_match_all("#(\\\$*)([\w:]+)\s*([+-\/%]|\*|\.)\s*(\\\$*)([\w:]+)#i", $valueString, $arr, PREG_SET_ORDER);
            //var_dump($arr);
            //直接赋值给一个变量,非变量运算
	        //Assign value directly to a variable, non-variable operation
            if (!isset($arr[0][5])) {
                if (substr($valueString, 0, 1) == '$') {
                    return $this->getMapValue(explode(':', substr($valueString, 1)), $this->data);
                } else {
                    throw new Exception($valueString . ':' . $this->errorsCode[self::E_VALUEFORMAT], self::E_VALUEFORMAT);
                }
            }

            if (trim($arr[0][1]) == '$') {
                //第一个值是变量
	            //The first value is the variable
                $val1 = $this->getMapValue(explode(':', trim($arr[0][2])), $this->data);
            } else {
                $val1 = trim($arr[0][2]);
            }
            if (trim($arr[0][4]) == '$') {
                //第一个值是变量
	            //The first value is the variable
                $val2 = $this->getMapValue(explode(':', trim($arr[0][5])), $this->data);
            } else {
                $val2 = trim($arr[0][5]);
            }
            //var_dump($val2);
            $ret = null;
            switch (trim($arr[0][3])) {
                case '+':
                    $ret = $val1 + $val2;
                    break;
                case '-':
                    $ret = $val1 - $val2;
                    break;
                case '*':
                    $ret = $val1 * $val2;
                    break;
                case '/':
                    $ret = $val1 / $val2;
                    break;
                case '%':
                    $ret = $val1 % $val2;
                    break;
                case '.':
                    $ret = $val1 . $val2;
                    break;
                default:
                    throw new Exception($valueString . ':' . $this->errorsCode[self::E_OPFORMAT], self::E_OPFORMAT);
            }
            return $ret;
        }
    }

    //获取一个指向数组元素的引用
	//Get a reference to an array element
    private function & findArrByKey($key, & $arr) {
        return $arr[$key];
    }

    private function _parse($ruleString) {
        $arr = explode(';', $ruleString);
        $matchs = array();
        $rules = array();
        foreach ($arr as $value) {
            if (empty($value)) {
                continue;
            }
            //var_dump($value);
            preg_match_all("#\\\$([\w|:]+)([\s|(|!=]+)(.+)#iu", $value, $matchs, PREG_SET_ORDER); //

            $object = $matchs[0][1];
            $tag = $matchs[0][2];
            $rules[$object] = array();
            $rules[$object]['or'] = array();
            $rules[$object]['and'] = array();
            if ($tag == '(') {
                //带多个子条件的运算
	            //Operation with multiple sub-conditions
                $conditions = explode(',', preg_replace("/\)$/i", '', $matchs[0][3]));
                foreach ($conditions as $c) {
                    $orconditions = explode('or', $c);
                    $orFlag = count($orconditions) > 1 ? TRUE : FALSE;
                    foreach ($orconditions as $orc) {
                        if (empty($orc)) {
                            continue;
                        }
                        $dd = array();

                        preg_match_all("#([\w]+)\s*(MEMBEROF|==|!=|>=|<=|>|<|NOT MEMBEROF|CONTAINS|NOT CONTAINS)\s*(.*)#i", $orc, $dd, PREG_SET_ORDER);
                        if (!isset($dd[0][3])) {
                            //比较运算符错误
	                        //Comparison operator error
                            throw new Exception($orc . ':' . $this->errorsCode[self::E_OPERATOR], self::E_OPERATOR);
                        }
                        $flag = $orFlag ? "or" : "and";
                        $rules[$object][$flag][] = array('key' => $dd[0][1], 'opr' => $dd[0][2], 'val' => trim($dd[0][3]), 'type' => 'sub');
                    }
                }
            } else {
                //直接运算
	            //Direct calculation
                $orconditions = explode('or', $matchs[0][0]);
                $orFlag = count($orconditions) > 1 ? TRUE : FALSE;
                foreach ($orconditions as $orc) {
                    if (empty($orc)) {
                        continue;
                    }
                    $dd = array();
                    preg_match_all("#([\w]+)\s*(MEMBEROF|==|!=|>=|<=|>|<|NOT MEMBEROF|CONTAINS|NOT CONTAINS)\s*(.*)#i", $orc, $dd, PREG_SET_ORDER);
                    $flag = $orFlag ? "or" : "and";
                    $rules[$object][$flag][] = array('key' => $dd[0][1], 'opr' => $dd[0][2], 'val' => trim($dd[0][3]), 'type' => 'self');
                }
            }
        }
        return $rules;
    }

    private function counting($object, $rule) {
        if (!isset($this->mapData[$object])) {
            throw new Exception($object . ':' . $this->errorsCode[self::E_KEYEXISTS], self::E_KEYEXISTS);
        }
        if ($rule['type'] == 'sub') {
            if (!isset($this->mapData[$object][$rule['key']])) {
                throw new Exception($object . '->' . $rule['key'] . ':' . $this->errorsCode[self::E_KEYEXISTS], self::E_KEYEXISTS);
            }
            $srcVal = $this->mapData[$object][$rule['key']];
        } else {
            $srcVal = $this->mapData[$object];
        }

        $value = $this->getRuleVal($rule['val']);

        $rule['opr'] = strtolower($rule['opr']);

        switch ($rule['opr']) {
            case '>':
                $ret = $this->_greaterThan($srcVal, $value);
                break;
            case '<':
                $ret = $this->_greaterThan($srcVal, $value, TRUE);
                break;
            case '>=':
                $ret = $this->_greaterThanEqual($srcVal, $value);
                break;
            case '<=':
                $ret = $this->_greaterThanEqual($srcVal, $value, TRUE);

                break;
            case '==':
                $ret = $this->_equal($srcVal, $value);
                break;
            case '!=':
                $ret = $this->_equal($srcVal, $value, TRUE);
                break;
            case 'memberof':
                $ret = $this->_memberof($srcVal, $value);
                break;
            case 'not memberof':
                $ret = $this->_memberof($srcVal, $value, TRUE);
                break;
            case 'contains':
                $ret = $this->_contains($srcVal, $value);
                break;
            case 'not contains':
                $ret = $this->_contains($srcVal, $value, TRUE);
                break;
            default:
                break;
        }
        return $ret;
    }

    /**
     * 将数据映射到绑定变量上
     * Map data to bind variables
     * @param array $methods eg. array('user','card') 对应user:card / Corresponding to user:card
     * @param array $data object's data
     */
    private function getMapValue($methods, $data) {
        foreach ($methods as $kv) {
            if (isset($data[$kv])) {
                $data = $data[$kv];
                continue;
            } else {
                $data = array();
            }
        }
        return $data;
    }

    /**
     * 解析rule里要比较的值是否是变量,如果是变量,取得其变量值,否则取其本身值
     * Analyze whether the value to be compared in the rule is a variable,
     * if it is a variable, get its variable value, otherwise take its own value
     * @param string $val
     * @return mix
     */
    private function getRuleVal($val) {
        $firstLetter = substr($val, 0, 1);
        if ($firstLetter == '$') {
            $method = substr($val, 1);
            $value = $this->getObjectValue(array($method), $this->data);
        } else {
            $value = $val;
        }
        return $value;
    }

    /**
     * 在data里查找keys链上的键的值
     * Find the value of the key on the keys chain in data
     * @param type $keys
     * @param type $data
     */
    private function getObjectValue($keys, $data) {
        foreach ($keys as $key) {
            if (isset($data[$key])) {
                $data = $data[$key];
            } else {
                return array();
            }
        }
        return $data;
    }

    /**
     * 等于和不等于计算
     * Equal and unequal calculation
     * @param type $srcVal 要计算的源值 / Source value to be calculated
     * @param type $value 要计算的目标值 / Target value to be calculated
     * @param type $isNot 不等于开关 / Not equal to switch
     * @return bool
     */
    private function _equal($srcVal, $value, $isNot = FALSE) {
        if ($isNot) {
            return trim($srcVal) != $value;
        } else {
            return trim($srcVal) == $value;
        }
    }

    /**
     * 大于等于和小于等于计算
     * Greater than or equal to and less than or equal to calculation
     * @param type $srcVal 要计算的源值 / Source value to be calculated
     * @param type $value 要计算的目标值 / Target value to be calculated
     * @param type $isNot 小于等于开关/ Less than or equal to switch
     * @return bool
     */
    private function _greaterThanEqual($srcVal, $value, $isNot = FALSE) {
        if ($isNot) {
            return $srcVal <= $value;
        } else {
            return $srcVal >= $value;
        }
    }

    /**
     * 大于和小于计算
     * Greater than and less than calculation
     * @param type $srcVal 要计算的源值 / Source value to be calculated
     * @param type $value 要计算的目标值 / Target value to be calculated
     * @param type $isNot 小于开关 / Less than switch
     * @return bool
     */
    private function _greaterThan($srcVal, $value, $isNot = FALSE) {
        if ($isNot) {
            return $srcVal < $value;
        } else {
            return $srcVal > $value;
        }
    }

    /**
     * 判断一个对象是否属于某对象,一个字符串是否属于另一个字符吅
     * Determine whether an object belongs to an object, and whether a string belongs to another character
     * @param mix $srcVal 源对象 / Source object
     * @param mix $value 判断源对象是否存在于此对象 / Determine whether the source object exists in this object
     * @param bool $isNot 不属于开关 / Not part of the switch
     * @return bool
     */
    private function _memberof($srcVal, $value, $isNot = FALSE) {
        //value是一个数组,则判断其值是否是其一个元素,如果是字符串,则判断是否存在于value中
	    //If value is an array, it is judged whether its value is one of its elements,
	    // and if it is a string, it is judged whether it exists in value
        if (is_array($value)) {
            if ($isNot) {
                return !in_array($srcVal, $value);
            } else {
                return in_array($srcVal, $value);
            }
        } else {
            if ($isNot) {
                return stristr($value, $srcVal) === FALSE;
            } else {
                return stristr($value, $srcVal) !== FALSE;
            }
        }
    }

    /**
     * 判断一个对象是否包含某元素,一个字符串是否包含有另一个字符吅
     * Determine whether an object contains a certain element, whether a string contains another character
     * @param mix $srcVal 源对象 / Source object
     * @param mix $value 判断是否存在于源对象的元素 / Determine whether the element exists in the source object
     * @param bool $isNot 不包含开关 / Does not include switch
     * @return bool
     */
    private function _contains($srcVal, $value, $isNot = FALSE) {
        //value是一个数组,则判断其值是否是其一个元素,
	    //value is an array, then judge whether its value is one of its elements,
        if (is_array($value)) {
            if ($isNot) {
                return !in_array($value, $srcVal);
            } else {
                return in_array($value, $srcVal);
            }
        } else {
            if ($isNot) {
                return stristr($srcVal, $value) === FALSE;
            } else {
                return stristr($srcVal, $value) !== FALSE;
            }
        }
    }

}
