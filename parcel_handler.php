<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");

// XML文件路径
$xmlFile = 'parcels.xml';

// 确保XML文件存在
if (!file_exists($xmlFile)) {
    $xml = new DOMDocument('1.0', 'UTF-8');
    $root = $xml->createElement('parcels');
    $xml->appendChild($root);
    $xml->save($xmlFile);
}

// 获取操作类型
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'getAll':
        getAllParcels();
        break;
    case 'add':
        addParcel();
        break;
    case 'delete':
        deleteParcel();
        break;
    case 'parseText':
        parseText();
        break;
    default:
        echo json_encode(['status' => 'error', 'message' => '无效的操作']);
}

/**
 * 获取所有快递信息
 */
function getAllParcels() {
    global $xmlFile;
    
    $xml = simplexml_load_file($xmlFile);
    $parcels = [];
    
    foreach ($xml->parcel as $parcel) {
        $parcels[] = [
            'id' => (string)$parcel['id'],
            'code' => (string)$parcel->code,
            'address' => (string)$parcel->address,
            'company' => (string)$parcel->company,
            'notes' => (string)$parcel->notes,
            'date' => (string)$parcel->date
        ];
    }
    
    // 按日期降序排序（最新的在前）
    usort($parcels, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
    
    echo json_encode([
        'status' => 'success',
        'data' => $parcels
    ]);
}

/**
 * 添加新快递信息
 */
function addParcel() {
    global $xmlFile;
    
    // 获取POST数据
    $code = $_POST['code'] ?? '';
    $address = $_POST['address'] ?? '';
    $company = $_POST['company'] ?? '';
    $notes = $_POST['notes'] ?? '';
    
    if (empty($code) || empty($address)) {
        echo json_encode([
            'status' => 'error',
            'message' => '取件码和地址不能为空'
        ]);
        return;
    }
    
    $xml = new DOMDocument('1.0', 'UTF-8');
    $xml->load($xmlFile);
    
    // 生成新ID
    $parcels = $xml->getElementsByTagName('parcel');
    $newId = 1;
    if ($parcels->length > 0) {
        $lastParcel = $parcels->item($parcels->length - 1);
        $newId = (int)$lastParcel->getAttribute('id') + 1;
    }
    
    // 创建新节点
    $newParcel = $xml->createElement('parcel');
    $newParcel->setAttribute('id', $newId);
    
    $codeNode = $xml->createElement('code', htmlspecialchars($code));
    $addressNode = $xml->createElement('address', htmlspecialchars($address));
    $companyNode = $xml->createElement('company', htmlspecialchars($company));
    $notesNode = $xml->createElement('notes', htmlspecialchars($notes));
    $dateNode = $xml->createElement('date', date('Y-m-d'));
    
    $newParcel->appendChild($codeNode);
    $newParcel->appendChild($addressNode);
    $newParcel->appendChild($companyNode);
    $newParcel->appendChild($notesNode);
    $newParcel->appendChild($dateNode);
    
    // 添加到根节点
    $xml->documentElement->appendChild($newParcel);
    
    // 保存XML文件
    $xml->preserveWhiteSpace = false;
    $xml->formatOutput = true;
    $xml->save($xmlFile);
    
    echo json_encode([
        'status' => 'success',
        'message' => '添加成功',
        'id' => $newId
    ]);
}

/**
 * 删除快递信息
 */
function deleteParcel() {
    global $xmlFile;
    
    $id = $_POST['id'] ?? '';
    
    if (empty($id)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'ID不能为空'
        ]);
        return;
    }
    
    $xml = new DOMDocument('1.0', 'UTF-8');
    $xml->load($xmlFile);
    
    $parcels = $xml->getElementsByTagName('parcel');
    
    foreach ($parcels as $parcel) {
        if ($parcel->getAttribute('id') == $id) {
            $xml->documentElement->removeChild($parcel);
            $xml->preserveWhiteSpace = false;
            $xml->formatOutput = true;
            $xml->save($xmlFile);
            
            echo json_encode([
                'status' => 'success',
                'message' => '删除成功'
            ]);
            return;
        }
    }
    
    echo json_encode([
        'status' => 'error',
        'message' => '未找到对应的记录'
    ]);
}

/**
 * 解析文本获取快递信息 - 优化了取件码识别逻辑
 */
function parseText() {
    $text = $_POST['text'] ?? '';
    
    if (empty($text)) {
        echo json_encode([
            'status' => 'error',
            'message' => '文本不能为空'
        ]);
        return;
    }
    
    // 初始化解析结果
    $result = [
        'code' => '',
        'address' => '',
        'company' => '',
        'notes' => $text
    ];
    
    // 1. 提取取件码（包含字母、数字和"-"，改进版正则表达式）
    // 匹配模式：以字母开头，后面跟数字和横线的组合，总长度通常在4-12个字符
    // 允许格式如：A22-19-9300、B-8-0223、C123456、D-7890、E12-3456等
    if (preg_match('/\b[A-Za-z]\d*(-\d+)+|\b[A-Za-z]-\d+(-\d+)*|\b[A-Za-z]\d{4,10}\b/', $text, $matches)) {
        $result['code'] = $matches[0];
    } 
    // 如果上面的模式没匹配到，尝试更通用的模式
    elseif (preg_match('/\b[A-Za-z0-9]+(?:-[A-Za-z0-9]+)+\b/', $text, $matches)) {
        $result['code'] = $matches[0];
    }
    
    // 2. 提取快递公司
    $companies = [
        '顺丰速运', '顺丰', 
        '中通快递', '中通', 
        '圆通快递', '圆通', 
        '申通快递', '申通', 
        '韵达快递', '韵达', 
        '百世快递', '百世', 
        '邮政快递', '邮政', 'EMS',
        '京东快递', '京东', 
        '极兔快递', '极兔',
        '德邦快递', '德邦',
        '宅急送'
    ];
    
    // 优先匹配全称，再匹配简称
    foreach ($companies as $company) {
        if (strpos($text, $company) !== false) {
            $result['company'] = $company;
            break;
        }
    }
    
    // 3. 提取地址（包含"柜"、"驿站"、"点"等关键词）
    if (preg_match('/[^\n]*?(南苑|北苑)[^\n]*/', $text, $matches)) {
        $result['address'] = $matches[0];
    }
    // 如果没找到上述关键词，尝试匹配包含小区/街道名称的地址
    elseif (preg_match('/[^\n]*?(天猫超市|体育馆)[^\n]*/', $text, $matches)) {
        $result['address'] = $matches[0];
    }
    
    echo json_encode([
        'status' => 'success',
        'data' => $result
    ]);
}
?>
