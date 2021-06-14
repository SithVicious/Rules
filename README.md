:cn:PHP简易规则引擎
============================
使用说明书(2016-08-11)
Tony.zhang
----------------------------
什么是规则引擎
    能Google的就不多说了。简易概括来说，就是通过解析更易定义的规则，来执行定义的动作，包括：重新赋值，调用自定义函数。对于后端特别多逻辑的业务，规则引擎能较好地节省开发时间，降低维护成本。JAVA有很多实现，但是PHP貌似还没有实现，所以做了这个轮子。
      
##一、规则定义

**1.关键字**
关键字非常少，只有五个：rule when then end call

rule:定义规则名称，如 rule rulename

when:规则的触发条件，表示其后的所有行都是条件语句，when需要单独占用一行。

then:规则的结果语句，表示其后的所有行都将顺序执行

call:结果语句中指定执行某函数

end:一个规则定义的结束标记

规则可以定义在一个文件里，一个规则文件可以定义多条规则。多个规则文件里如果有相同的规则名称，则最后一个规则会覆盖前一个规则。

/和#用来注释

*2.语法*
规则名称定义：关键字rule与规则名称以空格分隔，占用一行。

条件语句（when后的语句）：条件对象以$开头，以字母，数字或下划线组成，如 $user。语法为：
$绑定变量(元素名 操作符 值[,或or]…)，或者：$绑定变量操作符 值[, 或or]…。
每个条件对象都将绑定一个PHP的变量。用”:”表示多维关联数组元素，如$user:order将绑定到$user[‘order’]，依次类推。()表示此数组里的多个元素，如：$user(id==100,name==Tony) ;$order(price>100)。多个条件使用“;”分隔，多个条件间的关系是与操作。如前一个示例的含义是：$user数组的成员id等于100且name等于Tony，且$order的成员price大于100，则满足条件。

结果语句（then后的语句）：结果语句可以重新对用户输入数据赋值，也可以调用用户自定义函数。重新赋值语法为：$绑定变量(成员=新值,…)；如：$user:card(name=newName,id=$newid)。调用自定义函数的语法为：call(函数/方法名,参数一，参数二…)，如果要调用的是自定义的某个类的方法，如以->连接类名和方法名。如call(ClassName->method, $user:card,1);如上示例，参数可以是一个新的绑定变量。当前结果语句可以引用前一个结果语句赋值的绑定变量。
结果语句赋值时支持运算符”+ - * / %” 以及字符串连接符 “.” ，字符串连接符仅支持两个变量的连接。

代码示例：

    ##warehouse.drl
    //分快递
    rule AssignExpress 
    when
        $address contains 北京   or $address contains 北京市;$user:card(name==zhangtao,id not memberof $userids);
    then
        $user:card(name=tony,id=new);call(TestCommand->doTest15, $user:card,1,haha);
    end
    #分仓库
    rule AssignWarehouse
    when
        $user(name==zhangtao,);$order(goods_amount>100)
    then
        $order(mihome_id=120)
    end

*3.操作符*

此规则引擎支持以下操作符：

    操作符        说明        备注
    ==            等于    
    >             大于
    <             小于
    >=            大于等于
    <=            小于等于
    !=            不等于
    Memberof      属于        操作对象可以是数组，对象或字符串
    Not memberof  不属于  操作对象可以是数组，对象或字符串
    Contains      包含    操作对象可以是数组，对象或字符串
    Not contains  不包含  操作对象可以是数组，对象或字符串
    =             赋值    仅在结果语句中使用


*4.错误码定义*

    $errorsCode = 【
        '41000' => 'Data\'s key is not exists',
        '41001' => 'Value is not an array',
        '41002' => 'Rule file format error:Rule name is not difined',
        '41003' => 'Rule name is not exists',
        '41004' => 'Value\'s format is wrong',
        '41005' => 'Error operator',
        '41006' => 'Operator is wrong.The right operator are:== != > < >= <= , memberof , contains , not memberof , not contains',】

##二、使用入门
首先需要引用规则引擎类，对于x5项目，如下

    x5()->import("lib/Rules.php");
    $r = new Rules();

非x5项目，可以

    include “Rules.php”;
    $r = new Rules();

接下来：初始化规则文件

    $r->initRulesMap("warehouse.drl");
然后，输入要判断的数据

    $r->import($data);
最后，调用规则

    $r->execute(‘规则名称’);


##三、实战
一个电商网站每天产生很多订单，全国有两个仓库，规则1：北京的订单需要分配到北京仓，规则2：上海的订单需要分配到上海仓。规则3：上海的订单大于100的减10块运费；北京的订单小于90元的把用户ID标记在地址前面。

    DRL文件：order.drl
    ##订单分配逻辑
    rule AssignOrder
    when
        $address contains 北京 or $address contains 北京市;
    then
        $user:order(mihome=北京仓)
    when 
        $address contains 上海
    then
        $user:order(mihome=上海仓)    
    when 
        $user:order(mihome==上海仓);$user:order(price>100)
    then
        $user:order(price=$user:order:price-10)
    when
        $user:order(mihome==北京仓);$user:order(price<90)
    then
        $address=$user:order:id . $address
    end

测试数据1：

    $data = array(
        'user' => array(
            'order' => array(
                'order_id'=>1016,
                'id' => 8888,
                'name' => 'zhangsan',
                'price' => 200,
                'mihome'=>'',
                )
            ),
            'address' => "北京市朝阳区",
    );


执行结果前后对比1：
执行前

    Array
    (
        [user] => Array
        (
            [order] => Array
                (
                    [order_id] => 1016
                    [id] => 8888
                    [name] => zhangsan
                    [price] => 200
                    [mihome] => 
                )
        )
    [address] => 北京市朝阳区
    )

执行2

    Array
    (
    [user] => Array
        (
            [order] => Array
                (
                    [order_id] => 1016
                    [id] => 8888
                    [name] => zhangsan
                    [price] => 190
                    [mihome] => 北京仓
                )
        )
    [address] => 北京市朝阳区
    )

测试数据2：

    $data = array(
        'user' => array(
            'order' => array(
                'order_id'=>1017,
                'id' => 6666,
                'name' => 'lisi',
                'price' => 18,
                'mihome'=>'',
            )
        ),
        'address' => "上海市徐汇区",
    );


执行结果前后对比2：
执行前

    Array
    (
    [user] => Array
        (
            [order] => Array
                (
                    [order_id] => 1017
                    [id] => 6666
                    [name] => lisi
                    [price] => 18
                    [mihome] => 
                )
        )
    [address] => 上海市徐汇区
    )


执行后

    Array
    (
    [user] => Array
        (
            [order] => Array
                (
                    [order_id] => 1017
                    [id] => 6666
                    [name] => lisi
                    [price] => 18
                    [mihome] => 上海仓
                )
        )
    [address] => 6666上海市徐汇区
    )


:uk:PHP Simple Rule Engine
============================
User's manual (2016-08-11)
Tony.zhang
----------------------------
What is a rule engine

    Not much to say if you can Google. Simply put, it is to perform defined actions by parsing rules that are easier to define, including: reassigning values and calling custom functions. For businesses that have a lot of logic in the back-end, the rule engine can save development time and reduce maintenance costs. JAVA has many implementations, but PHP seems to have not been implemented yet, so I made this wheel.
      
##1. Rule definition

**1.Keywords**
There are very few keywords, only five: rule when then end call

rule: Define the name of the rule, such as rule rulename

when: The triggering condition of the rule, which means that all subsequent lines are conditional statements, and when needs to occupy a separate line.

then: the result statement of the rule, which means that all subsequent lines will be executed sequentially

call: specify a function to be executed in the result statement

end: the end tag of a rule definition

Rules can be defined in one file, and one rule file can define multiple rules. If multiple rule files have the same rule name, the last rule will overwrite the previous rule.

/ And # are used to comment

*2. Grammar*
Rule name definition: The keyword rule and the rule name are separated by a space and occupy one line.

Conditional statement (statement after when): The conditional object starts with $ and is composed of letters, numbers or underscores, such as $user. The syntax is:
$Bind variable (element name operator value [, or or]...), or: $Bind variable operator value [, or or]....
Each condition object will bind a PHP variable. Use ":" to denote multi-dimensional associative array elements. For example, $user:order will be bound to $user[‘order’], and so on. () means multiple elements in this array, such as: $user(id==100,name==Tony); $order(price>100). Multiple conditions are separated by ";", and the relationship between multiple conditions is AND operation. For example, the meaning of the previous example is: if the member id of the $user array is equal to 100 and the name is equal to Tony, and the member price of the $order is greater than 100, the condition is met.

Result sentence (sentence after then): The result sentence can re-assign the user input data or call user-defined functions. The reassignment syntax is: $bind variable (member=new value,...); such as: $user:card(name=newName,id=$newid). The syntax for calling a custom function is: call (function/method name, parameter one, parameter two...). If you want to call a custom method of a certain class, for example, connect the class name and method name with ->. Such as call(ClassName->method, $user:card,1); as in the above example, the parameter can be a new bind variable. The current result statement can refer to the bind variable assigned by the previous result statement.
The operator "+-* / %" and the string concatenator "." are supported when assigning the result statement. The string concatenator only supports the concatenation of two variables.

Code example:

    ##warehouse.drl
    //Sub-express
    rule AssignExpress 
    when
        $address contains Beijing;$user:card(name==zhangtao,id not memberof $userids);
    then
        $user:card(name=tony,id=new);call(TestCommand->doTest15, $user:card,1,haha);
    end
    #Sub-warehouse
    rule AssignWarehouse
    when
        $user(name==zhangtao,);$order(goods_amount>100)
    then
        $order(mihome_id=120)
    end


*3. Operator*

This rule engine supports the following operators:

     Operator Description Remark
     ==                      Equals
     >                       Greater than
     <                       Less than
     >=                      Greater than or equal
     <=                      Less than or equal to
     !=                      Not equal
     Memberof                Belongs to the operation object can be an array, object or string
     Not memberof            Does not belong to the operation object can be an array, object or string
     Contains                Contains the operation object can be an array, object or string
     Not contains            Does not contain the operation object can be an array, object or string
     =                       Assignment is only used in the result statement


*4. Error code definition*

    $errorsCode = 【
        '41000' => 'Data\'s key is not exists',
        '41001' => 'Value is not an array',
        '41002' => 'Rule file format error:Rule name is not difined',
        '41003' => 'Rule name is not exists',
        '41004' => 'Value\'s format is wrong',
        '41005' => 'Error operator',
        '41006' => 'Operator is wrong.The right operator are:== != > < >= <= , memberof , contains , not memberof , not contains',】

##2. Getting started
First, you need to reference the rule engine class, for the x5 project, as follows

    x5()->import("lib/Rules.php");
    $r = new Rules();

Non-x5 items, you can

    include "Rules.php";
    $r = new Rules();

Next: Initialize the rules file

    $r->initRulesMap("warehouse.drl");
Then, enter the data to be judged

    $r->import($data);
Finally, call the rules

    $r->execute(‘Rule name’);


##3. Actual combat
An e-commerce website generates many orders every day. There are two warehouses nationwide. Rule 1: Beijing orders need to be allocated to the Beijing warehouse, and rule 2: Shanghai orders need to be allocated to the Shanghai warehouse. Rule 3: Shanghai orders greater than 100 minus 10 yuan shipping; Beijing orders less than 90 yuan, mark the user ID in front of the address.

    DRL File：order.drl
    ##Order allocation logic
    rule AssignOrder
    when
        $address contains Beijing;
    then
        $user:order(mihome=Beijing warehouse)
    when 
        $address contains Shanghai
    then
        $user:order(mihome=Shanghai warehouse)    
    when 
        $user:order(mihome==Shanghai warehouse);$user:order(price>100)
    then
        $user:order(price=$user:order:price-10)
    when
        $user:order(mihome==Beijing warehouse);$user:order(price<90)
    then
        $address=$user:order:id . $address
    end

Test data 1

    $data = array(
        'user' => array(
            'order' => array(
                'order_id'=>1016,
                'id' => 8888,
                'name' => 'zhangsan',
                'price' => 200,
                'mihome'=>'',
                )
            ),
            'address' => "Chaoyang District, Beijing",
    );


Comparison of the results before and after 1:
Before execution

    Array
    (
        [user] => Array
        (
            [order] => Array
                (
                    [order_id] => 1016
                    [id] => 8888
                    [name] => zhangsan
                    [price] => 200
                    [mihome] => 
                )
        )
    [address] => Chaoyang District, Beijing
    )

执行2

    Array
    (
    [user] => Array
        (
            [order] => Array
                (
                    [order_id] => 1016
                    [id] => 8888
                    [name] => zhangsan
                    [price] => 190
                    [mihome] => Beijing warehouse
                )
        )
    [address] => Chaoyang District, Beijing
    )

Test data 2:

    $data = array(
        'user' => array(
            'order' => array(
                'order_id'=>1017,
                'id' => 6666,
                'name' => 'lisi',
                'price' => 18,
                'mihome'=>'',
            )
        ),
        'address' => "Xuhui District, Shanghai",
    );


Comparison of the results before and after 2:
Before execution

    Array
    (
    [user] => Array
        (
            [order] => Array
                (
                    [order_id] => 1017
                    [id] => 6666
                    [name] => lisi
                    [price] => 18
                    [mihome] => 
                )
        )
    [address] => Xuhui District, Shanghai
    )


After execution

    Array
    (
    [user] => Array
        (
            [order] => Array
                (
                    [order_id] => 1017
                    [id] => 6666
                    [name] => lisi
                    [price] => 18
                    [mihome] => Shanghai warehouse
                )
        )
    [address] => 6666 Xuhui District, Shanghai
    )



