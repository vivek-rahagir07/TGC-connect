<?php
/**
 * TGC Connect - Enterprise Inventory & Asset Management API
 */

require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getJsonInput();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && empty($action)) {
    $action = $input['action'] ?? '';
}

$user = getCurrentUser($pdo);
if (!$user) {
    sendResponse(false, ['message' => 'Authentication required.'], 401);
}

// 1. Employee Self-Service Endpoint (View items issued to me)
if ($action === 'my_items') {
    $stmt = $pdo->prepare("
        SELECT 
            ii.*, 
            i.name AS item_name, 
            i.category, 
            i.unit, 
            i.location
        FROM inventory_issuances ii
        JOIN inventories i ON ii.inventory_id = i.id
        WHERE ii.user_id = ? OR ii.recipient_name = ?
        ORDER BY ii.id DESC
    ");
    $stmt->execute([$user['id'], $user['name']]);
    $items = $stmt->fetchAll();
    sendResponse(true, ['items' => $items]);
}

// All subsequent actions require Administrator access
if ($user['role'] !== 'admin') {
    sendResponse(false, ['message' => 'Unauthorized. Administrator privilege required.'], 403);
}

switch ($action) {
    // 2. High-Level Inventory Stats & KPIs
    case 'stats':
        $totalItems = (int) $pdo->query("SELECT COUNT(*) FROM inventories")->fetchColumn();
        $totalUnits = (int) $pdo->query("SELECT COALESCE(SUM(total_quantity), 0) FROM inventories")->fetchColumn();
        $availableUnits = (int) $pdo->query("SELECT COALESCE(SUM(available_quantity), 0) FROM inventories")->fetchColumn();
        $issuedUnits = max(0, $totalUnits - $availableUnits);

        // Low stock count (items where available_quantity <= min_stock_alert)
        $lowStockCount = (int) $pdo->query("SELECT COUNT(*) FROM inventories WHERE available_quantity <= min_stock_alert")->fetchColumn();

        // Active active unreturned issuances
        $activeIssuancesCount = (int) $pdo->query("SELECT COUNT(*) FROM inventory_issuances WHERE status = 'issued'")->fetchColumn();

        sendResponse(true, [
            'total_items' => $totalItems,
            'total_units' => $totalUnits,
            'available_units' => $availableUnits,
            'issued_units' => $issuedUnits,
            'low_stock_count' => $lowStockCount,
            'active_issuances_count' => $activeIssuancesCount
        ]);
        break;

    // 3. List Inventory Items
    case 'list':
        $category = trim($_GET['category'] ?? '');
        $search = trim($_GET['search'] ?? '');
        $stockFilter = trim($_GET['stock_filter'] ?? 'all');

        $query = "SELECT * FROM inventories WHERE 1=1";
        $params = [];

        if (!empty($category) && $category !== 'all') {
            $query .= " AND category = ?";
            $params[] = $category;
        }

        if (!empty($search)) {
            $query .= " AND (name LIKE ? OR description LIKE ? OR location LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        if ($stockFilter === 'low_stock') {
            $query .= " AND available_quantity <= min_stock_alert AND available_quantity > 0";
        } elseif ($stockFilter === 'out_of_stock') {
            $query .= " AND available_quantity <= 0";
        } elseif ($stockFilter === 'in_stock') {
            $query .= " AND available_quantity > min_stock_alert";
        }

        $query .= " ORDER BY (available_quantity <= min_stock_alert) DESC, name ASC";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        // Attach issuance statistics to each item
        foreach ($items as &$item) {
            $item['total_quantity'] = (int) $item['total_quantity'];
            $item['available_quantity'] = (int) $item['available_quantity'];
            $item['min_stock_alert'] = (int) $item['min_stock_alert'];
            $item['issued_quantity'] = max(0, $item['total_quantity'] - $item['available_quantity']);
            $item['is_low_stock'] = ($item['available_quantity'] <= $item['min_stock_alert']);
            $item['is_out_of_stock'] = ($item['available_quantity'] <= 0);
        }

        // Distinct categories for filter dropdown
        $categories = $pdo->query("SELECT DISTINCT category FROM inventories WHERE category IS NOT NULL ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);

        sendResponse(true, [
            'items' => $items,
            'categories' => $categories
        ]);
        break;

    // 4. Add New Inventory Item
    case 'add_item':
        $name = trim($input['name'] ?? '');
        $category = trim($input['category'] ?? 'Stationery & Supplies');
        $unit = trim($input['unit'] ?? 'Pieces');
        $quantity = (int) ($input['quantity'] ?? 0);
        $minStockAlert = (int) ($input['min_stock_alert'] ?? 5);
        $location = trim($input['location'] ?? 'Stationery Cabinet');
        $description = trim($input['description'] ?? '');

        if (empty($name)) {
            sendResponse(false, ['message' => 'Item name is required.'], 400);
        }

        if ($quantity < 0) {
            sendResponse(false, ['message' => 'Initial quantity cannot be negative.'], 400);
        }

        $stmt = $pdo->prepare("
            INSERT INTO inventories (name, category, unit, total_quantity, available_quantity, min_stock_alert, location, description)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $category, $unit, $quantity, $quantity, $minStockAlert, $location, $description]);
        $newId = $pdo->lastInsertId();

        logAdminAction($pdo, $user['id'], 'inventory_add', null, "Cataloged new inventory item: {$name} ({$quantity} {$unit})");

        sendResponse(true, [
            'message' => "Item '{$name}' added successfully to inventory.",
            'item_id' => $newId
        ]);
        break;

    // 5. Update Inventory Item Details
    case 'update_item':
        $id = (int) ($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        $category = trim($input['category'] ?? 'Stationery & Supplies');
        $unit = trim($input['unit'] ?? 'Pieces');
        $minStockAlert = (int) ($input['min_stock_alert'] ?? 5);
        $location = trim($input['location'] ?? '');
        $description = trim($input['description'] ?? '');

        if (!$id || empty($name)) {
            sendResponse(false, ['message' => 'Valid item ID and name are required.'], 400);
        }

        $stmt = $pdo->prepare("
            UPDATE inventories 
            SET name = ?, category = ?, unit = ?, min_stock_alert = ?, location = ?, description = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $category, $unit, $minStockAlert, $location, $description, $id]);

        logAdminAction($pdo, $user['id'], 'inventory_update', null, "Updated item details for #{$id}: {$name}");

        sendResponse(true, ['message' => "Item '{$name}' updated successfully."]);
        break;

    // 6. Restock / Add Quantity to Existing Item
    case 'restock':
        $id = (int) ($input['id'] ?? 0);
        $addQuantity = (int) ($input['quantity'] ?? 0);
        $notes = trim($input['notes'] ?? '');

        if (!$id || $addQuantity <= 0) {
            sendResponse(false, ['message' => 'Valid item ID and positive restock quantity are required.'], 400);
        }

        $stmt = $pdo->prepare("SELECT * FROM inventories WHERE id = ?");
        $stmt->execute([$id]);
        $item = $stmt->fetch();

        if (!$item) {
            sendResponse(false, ['message' => 'Inventory item not found.'], 404);
        }

        $newTotal = (int) $item['total_quantity'] + $addQuantity;
        $newAvailable = (int) $item['available_quantity'] + $addQuantity;

        $updateStmt = $pdo->prepare("UPDATE inventories SET total_quantity = ?, available_quantity = ? WHERE id = ?");
        $updateStmt->execute([$newTotal, $newAvailable, $id]);

        logAdminAction($pdo, $user['id'], 'inventory_restock', null, "Restocked +{$addQuantity} {$item['unit']} of {$item['name']}. New total: {$newTotal}");

        sendResponse(true, [
            'message' => "Successfully added {$addQuantity} {$item['unit']} to {$item['name']}.",
            'total_quantity' => $newTotal,
            'available_quantity' => $newAvailable
        ]);
        break;

    // 7. Delete Item
    case 'delete_item':
        $id = (int) ($input['id'] ?? ($_GET['id'] ?? 0));
        if (!$id) {
            sendResponse(false, ['message' => 'Item ID is required.'], 400);
        }

        // Check if there are active unreturned issuances
        $stmtActive = $pdo->prepare("SELECT COUNT(*) FROM inventory_issuances WHERE inventory_id = ? AND status = 'issued'");
        $stmtActive->execute([$id]);
        $activeCount = (int) $stmtActive->fetchColumn();

        if ($activeCount > 0) {
            sendResponse(false, [
                'message' => "Cannot delete item: {$activeCount} unit(s) are currently issued and out. Please process their return first."
            ], 400);
        }

        $stmtName = $pdo->prepare("SELECT name FROM inventories WHERE id = ?");
        $stmtName->execute([$id]);
        $itemName = $stmtName->fetchColumn() ?: "Item #{$id}";

        $delStmt = $pdo->prepare("DELETE FROM inventories WHERE id = ?");
        $delStmt->execute([$id]);

        logAdminAction($pdo, $user['id'], 'inventory_delete', null, "Deleted inventory item: {$itemName}");

        sendResponse(true, ['message' => "Item '{$itemName}' removed from catalog."]);
        break;

    // 8. Issue Item / Give to Someone
    case 'issue':
        $inventoryId = (int) ($input['inventory_id'] ?? 0);
        $userId = !empty($input['user_id']) ? (int) $input['user_id'] : null;
        $recipientName = trim($input['recipient_name'] ?? '');
        $recipientType = trim($input['recipient_type'] ?? 'employee');
        $quantity = (int) ($input['quantity'] ?? 1);
        $issueDate = trim($input['issue_date'] ?? date('Y-m-d'));
        $expectedReturnDate = !empty($input['expected_return_date']) ? trim($input['expected_return_date']) : null;
        $isReturnable = isset($input['is_returnable']) ? (int) $input['is_returnable'] : 1;
        $purpose = trim($input['purpose'] ?? '');

        if (!$inventoryId) {
            sendResponse(false, ['message' => 'Please select an inventory item to issue.'], 400);
        }

        // If user_id provided, autofill recipient_name if empty
        if ($userId && empty($recipientName)) {
            $stmtUser = $pdo->prepare("SELECT name, department FROM users WHERE id = ?");
            $stmtUser->execute([$userId]);
            $u = $stmtUser->fetch();
            if ($u) {
                $recipientName = $u['name'];
            }
        }

        if (empty($recipientName)) {
            sendResponse(false, ['message' => 'Recipient name is required (who the item is being given to).'], 400);
        }

        if ($quantity <= 0) {
            sendResponse(false, ['message' => 'Quantity must be at least 1.'], 400);
        }

        // Check stock availability
        $stmtItem = $pdo->prepare("SELECT * FROM inventories WHERE id = ?");
        $stmtItem->execute([$inventoryId]);
        $item = $stmtItem->fetch();

        if (!$item) {
            sendResponse(false, ['message' => 'Selected inventory item does not exist.'], 404);
        }

        $available = (int) $item['available_quantity'];
        if ($available < $quantity) {
            sendResponse(false, [
                'message' => "Insufficient stock! Only {$available} {$item['unit']} currently available in inventory."
            ], 400);
        }

        // If consumable (not returnable), set initial status to 'consumed' or 'issued'
        $initialStatus = $isReturnable ? 'issued' : 'consumed';

        // Decrement available stock
        $newAvailable = $available - $quantity;
        $updStock = $pdo->prepare("UPDATE inventories SET available_quantity = ? WHERE id = ?");
        $updStock->execute([$newAvailable, $inventoryId]);

        // Insert issuance record
        $stmtIss = $pdo->prepare("
            INSERT INTO inventory_issuances 
            (inventory_id, user_id, recipient_name, recipient_type, quantity, issue_date, expected_return_date, is_returnable, status, returned_quantity, issued_by, purpose)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
        ");
        $stmtIss->execute([
            $inventoryId, 
            $userId, 
            $recipientName, 
            $recipientType, 
            $quantity, 
            $issueDate, 
            $expectedReturnDate, 
            $isReturnable, 
            $initialStatus, 
            $user['id'], 
            $purpose
        ]);
        $issuanceId = $pdo->lastInsertId();

        logAdminAction($pdo, $user['id'], 'inventory_issue', $userId, "Issued {$quantity} {$item['unit']} of {$item['name']} to {$recipientName}");

        sendResponse(true, [
            'message' => "Successfully issued {$quantity} {$item['unit']} of '{$item['name']}' to {$recipientName}.",
            'issuance_id' => $issuanceId,
            'remaining_stock' => $newAvailable
        ]);
        break;

    // 9. Process Return of an Issued Item
    case 'return':
        $issuanceId = (int) ($input['issuance_id'] ?? 0);
        $returnQuantity = (int) ($input['quantity'] ?? 0);
        $markConsumed = !empty($input['mark_consumed']);
        $notes = trim($input['notes'] ?? '');

        if (!$issuanceId) {
            sendResponse(false, ['message' => 'Issuance record ID is required.'], 400);
        }

        $stmtIss = $pdo->prepare("SELECT * FROM inventory_issuances WHERE id = ?");
        $stmtIss->execute([$issuanceId]);
        $issuance = $stmtIss->fetch();

        if (!$issuance) {
            sendResponse(false, ['message' => 'Issuance record not found.'], 404);
        }

        if ($issuance['status'] === 'returned') {
            sendResponse(false, ['message' => 'This item has already been marked as returned.'], 400);
        }

        $issuedQty = (int) $issuance['quantity'];
        $alreadyReturned = (int) $issuance['returned_quantity'];
        $remainingToReturn = max(0, $issuedQty - $alreadyReturned);

        if ($returnQuantity <= 0 || $returnQuantity > $remainingToReturn) {
            $returnQuantity = $remainingToReturn;
        }

        $stmtItem = $pdo->prepare("SELECT * FROM inventories WHERE id = ?");
        $stmtItem->execute([$issuance['inventory_id']]);
        $item = $stmtItem->fetch();

        if (!$item) {
            sendResponse(false, ['message' => 'Associated inventory item not found.'], 404);
        }

        $newReturnedQty = $alreadyReturned + $returnQuantity;
        $isFullReturn = ($newReturnedQty >= $issuedQty);
        $newStatus = $markConsumed ? 'consumed' : ($isFullReturn ? 'returned' : 'issued');
        $nowStr = date('Y-m-d H:i:s');

        // If returned to shelf (not consumed), increment available stock
        if (!$markConsumed) {
            $newAvailable = (int) $item['available_quantity'] + $returnQuantity;
            // Cap available at total_quantity to prevent data drift
            $newAvailable = min((int)$item['total_quantity'], $newAvailable);

            $updStock = $pdo->prepare("UPDATE inventories SET available_quantity = ? WHERE id = ?");
            $updStock->execute([$newAvailable, $item['id']]);
        }

        // Update issuance
        $stmtUpd = $pdo->prepare("
            UPDATE inventory_issuances 
            SET returned_quantity = ?, status = ?, returned_date = ?
            WHERE id = ?
        ");
        $stmtUpd->execute([$newReturnedQty, $newStatus, $nowStr, $issuanceId]);

        logAdminAction(
            $pdo, 
            $user['id'], 
            'inventory_return', 
            $issuance['user_id'], 
            "Processed return of {$returnQuantity} {$item['unit']} of {$item['name']} from {$issuance['recipient_name']}"
        );

        sendResponse(true, [
            'message' => "Returned {$returnQuantity} {$item['unit']} of '{$item['name']}' from {$issuance['recipient_name']} back to stock.",
            'status' => $newStatus,
            'returned_quantity' => $newReturnedQty
        ]);
        break;

    // 10. Issuances History & Assignment Log
    case 'issuances':
        $statusFilter = trim($_GET['status'] ?? 'all');
        $inventoryId = (int) ($_GET['inventory_id'] ?? 0);
        $search = trim($_GET['search'] ?? '');

        $query = "
            SELECT 
                ii.*,
                i.name AS item_name,
                i.category,
                i.unit,
                i.location,
                u.email AS user_email,
                u.department AS user_department,
                admin.name AS issuer_name
            FROM inventory_issuances ii
            JOIN inventories i ON ii.inventory_id = i.id
            LEFT JOIN users u ON ii.user_id = u.id
            LEFT JOIN users admin ON ii.issued_by = admin.id
            WHERE 1=1
        ";
        $params = [];

        if (!empty($statusFilter) && $statusFilter !== 'all') {
            $query .= " AND ii.status = ?";
            $params[] = $statusFilter;
        }

        if ($inventoryId > 0) {
            $query .= " AND ii.inventory_id = ?";
            $params[] = $inventoryId;
        }

        if (!empty($search)) {
            $query .= " AND (ii.recipient_name LIKE ? OR i.name LIKE ? OR ii.purpose LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $query .= " ORDER BY ii.id DESC";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $issuances = $stmt->fetchAll();

        sendResponse(true, ['issuances' => $issuances]);
        break;

    default:
        sendResponse(false, ['message' => "Invalid or unrecognized inventory action: '{$action}'"], 400);
}
