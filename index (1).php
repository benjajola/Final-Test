<?php
// GAMEVAULT - Single-file PHP + MySQL e-commerce demo
session_start();
const DB_HOST = '127.0.0.1';
const DB_NAME = 'gamevault';
const DB_USER = 'root';
const DB_PASS = ''; // Change if your MySQL has a password.

function db() {
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO(
            'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($v) { return '$'.number_format((float)$v, 2); }
function go($page='home') { header('Location: ?page='.$page); exit; }
function logged() { return isset($_SESSION['user']); }
function admin() { return ($_SESSION['user']['role'] ?? '') === 'admin'; }
function require_login() { if (!logged()) go('login'); }
function csrf() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    echo '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">';
}
function verify_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
        !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419); exit('Invalid form token. Please go back and try again.');
    }
}
function flash($message) { $_SESSION['flash'] = $message; }
function cart_items() {
    $cart = $_SESSION['cart'] ?? [];
    if (!$cart) return [];
    $ids = array_map('intval', array_keys($cart));
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $q = db()->prepare("SELECT * FROM products WHERE active=1 AND id IN ($marks)");
    $q->execute($ids);
    $items = [];
    foreach ($q as $p) {
        $qty = min((int)$p['stock'], max(0, (int)$cart[$p['id']]));
        if ($qty > 0) $items[] = ['product'=>$p, 'qty'=>$qty, 'subtotal'=>$qty*$p['price']];
    }
    return $items;
}
function cart_total() {
    return array_sum(array_column(cart_items(), 'subtotal'));
}
verify_csrf();

$page = $_GET['page'] ?? 'home';
$title = 'GameVault';
$error = '';

if ($page === 'logout') {
    $_SESSION = [];
    session_destroy();
    go('home');
}
if ($page === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $q = db()->prepare('SELECT stock FROM products WHERE id=? AND active=1');
    $q->execute([$id]); $stock = $q->fetchColumn();
    if ($stock !== false && $stock > 0) {
        $_SESSION['cart'][$id] = min((int)$stock, (int)($_SESSION['cart'][$id] ?? 0) + max(1, (int)($_POST['qty'] ?? 1)));
        flash('Product added to your cart.');
    } else flash('This product is unavailable.');
    go('home');
}
if ($page === 'remove') {
    unset($_SESSION['cart'][(int)($_GET['id'] ?? 0)]);
    go('cart');
}
if ($page === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (($_POST['qty'] ?? []) as $id=>$qty) {
        $id = (int)$id; $qty = (int)$qty;
        $q = db()->prepare('SELECT stock FROM products WHERE id=?');
        $q->execute([$id]); $stock = $q->fetchColumn();
        if ($stock === false || $qty <= 0) unset($_SESSION['cart'][$id]);
        else $_SESSION['cart'][$id] = min($stock, $qty);
    }
    go('cart');
}
if ($page === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        $error = 'Enter a name, valid email and password with at least 8 characters.';
    } else {
        try {
            $q = db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)');
            $q->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $_SESSION['user'] = ['id'=>db()->lastInsertId(), 'name'=>$name, 'email'=>$email, 'role'=>'customer'];
            go('home');
        } catch (PDOException $ex) { $error = 'That email is already registered.'; }
    }
}
if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $q = db()->prepare('SELECT * FROM users WHERE email=?');
    $q->execute([strtolower(trim($_POST['email'] ?? ''))]);
    $u = $q->fetch();
    if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id'=>$u['id'], 'name'=>$u['name'], 'email'=>$u['email'], 'role'=>$u['role']];
        go('home');
    } else $error = 'Email or password is incorrect.';
}
if ($page === 'checkout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_login();
    $method = $_POST['payment'] ?? '';
    if (!in_array($method, ['PIX','Boleto','Card'], true)) $error = 'Choose a payment method.';
    elseif (!cart_items()) go('cart');
    else {
        try {
            $pdo = db(); $pdo->beginTransaction();
            $items = cart_items(); $total = 0;
            foreach ($items as $item) {
                $q = $pdo->prepare('SELECT stock,price FROM products WHERE id=? FOR UPDATE');
                $q->execute([$item['product']['id']]); $current = $q->fetch();
                if (!$current || $current['stock'] < $item['qty']) throw new Exception('Stock changed. Please update your cart.');
                $total += $current['price'] * $item['qty'];
            }
            $q = $pdo->prepare('INSERT INTO orders(user_id,total,payment_method) VALUES(?,?,?)');
            $q->execute([$_SESSION['user']['id'], $total, $method]);
            $orderId = $pdo->lastInsertId();
            foreach ($items as $item) {
                $pid = $item['product']['id']; $qty = $item['qty'];
                $q = $pdo->prepare('SELECT price FROM products WHERE id=?'); $q->execute([$pid]); $price = $q->fetchColumn();
                $q = $pdo->prepare('INSERT INTO order_items(order_id,product_id,quantity,unit_price) VALUES(?,?,?,?)');
                $q->execute([$orderId,$pid,$qty,$price]);
                $q = $pdo->prepare('UPDATE products SET stock=stock-? WHERE id=?'); $q->execute([$qty,$pid]);
            }
            $pdo->commit(); $_SESSION['cart'] = []; $_SESSION['last_order'] = $orderId; go('success');
        } catch (Throwable $ex) {
            if (db()->inTransaction()) db()->rollBack();
            $error = $ex->getMessage();
        }
    }
}
if ($page === 'admin_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin()) { http_response_code(403); exit('Admin access required.'); }
    $id=(int)($_POST['id']??0); $name=trim($_POST['name']??'');
    $description=trim($_POST['description']??''); $price=(float)($_POST['price']??0);
    $stock=(int)($_POST['stock']??0); $category=(int)($_POST['category_id']??0);
    $emoji=trim($_POST['emoji']??'🎮');
    if (!$name || $price<=0 || $stock<0) $error='Enter a name, positive price and valid stock.';
    else {
        if ($id) {
            $q=db()->prepare('UPDATE products SET name=?,description=?,price=?,stock=?,category_id=?,emoji=? WHERE id=?');
            $q->execute([$name,$description,$price,$stock,$category,$emoji,$id]);
        } else {
            $q=db()->prepare('INSERT INTO products(name,description,price,stock,category_id,emoji) VALUES(?,?,?,?,?,?)');
            $q->execute([$name,$description,$price,$stock,$category,$emoji]);
        }
        go('admin');
    }
}
if ($page === 'admin_off' && admin()) {
    $q=db()->prepare('UPDATE products SET active=0 WHERE id=?'); $q->execute([(int)($_GET['id']??0)]); go('admin');
}
if ($page === 'admin_on' && admin()) {
    $q=db()->prepare('UPDATE products SET active=1 WHERE id=?'); $q->execute([(int)($_GET['id']??0)]); go('admin');
}
if ($page === 'admin' && !admin()) { http_response_code(403); exit('Admin access required.'); }

function header_html($title) {
    $count = array_sum($_SESSION['cart'] ?? []);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.e($title).' | GameVault</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><style>
    @import url("https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700&family=Space+Grotesk:wght@600;700&display=swap");
    :root{--bg:#0c1323;--panel:#151f34;--line:#2c3b59;--muted:#9baaca}body{background:var(--bg);color:#f1f5ff;font-family:"DM Sans",sans-serif;min-height:100vh}h1,h2,h3,.navbar-brand{font-family:"Space Grotesk",sans-serif}.navbg{background:#070c17;border-bottom:1px solid var(--line)}.muted{color:var(--muted)}.hero{padding:55px 42px;border-radius:24px;background:radial-gradient(circle at 85%,#49318a,transparent 35%),linear-gradient(120deg,#1c2b4b,#10192b);border:1px solid var(--line)}.hero h1{font-size:clamp(2.5rem,6vw,4.5rem)}.panel,.product{background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:22px}.product{padding:0;overflow:hidden;height:100%}.art{height:150px;display:grid;place-items:center;font-size:5rem;background:linear-gradient(145deg,#263b61,#151f34)}.pbody{padding:18px}.price{font-size:1.2rem;font-weight:700}.form-control,.form-select{background:#101a2e;color:white;border-color:#3a4967}.form-control:focus,.form-select:focus{background:#101a2e;color:white}.btn-primary{background:linear-gradient(100deg,#8b5cf6,#5865e8);border:0}.table{--bs-table-bg:transparent;--bs-table-color:#eef3ff;--bs-table-border-color:var(--line)}a{color:#9beafa}.detailart{font-size:9rem;text-align:center;padding:55px;background:#182640;border-radius:20px}.stat{background:#1b2945;border:1px solid var(--line);padding:20px;border-radius:16px}.stat strong{font-size:2rem}
    </style></head><body><nav class="navbar navbar-expand-lg navbar-dark navbg"><div class="container"><a class="navbar-brand fw-bold" href="?">🎮 GAMEVAULT</a><button class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button><div class="collapse navbar-collapse" id="nav"><ul class="navbar-nav me-auto"><li class="nav-item"><a class="nav-link" href="?">Store</a></li>'.(admin()?'<li class="nav-item"><a class="nav-link" href="?page=admin">Admin</a></li>':'').'</ul><a class="nav-link me-3" href="?page=cart">Cart ('.$count.')</a>'.(logged()?'<span class="navbar-text me-3">Hi, '.e($_SESSION['user']['name']).'</span><a class="btn btn-outline-light btn-sm" href="?page=logout">Sign out</a>':'<a class="btn btn-outline-light btn-sm" href="?page=login">Sign in</a>').'</div></div></nav><main class="container py-4">';
    if (!empty($_SESSION['flash'])) { echo '<div class="alert alert-info">'.e($_SESSION['flash']).'</div>'; unset($_SESSION['flash']); }
    if (!empty($GLOBALS['error'])) echo '<div class="alert alert-danger">'.e($GLOBALS['error']).'</div>';
}
function footer_html() { echo '</main><footer class="container py-4 mt-5 border-top muted">GAMEVAULT · Level up your setup. <span class="float-end">© '.date('Y').'</span></footer><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script></body></html>'; }

header_html($title);

if ($page === 'home') {
    $search=trim($_GET['q']??''); $category=(int)($_GET['category']??0);
    $cats=db()->query('SELECT * FROM categories ORDER BY name')->fetchAll();
    $sql='SELECT p.*,c.name AS category FROM products p JOIN categories c ON c.id=p.category_id WHERE p.active=1'; $args=[];
    if($search){$sql.=' AND (p.name LIKE ? OR p.description LIKE ?)';$args[]="%$search%";$args[]="%$search%";}
    if($category){$sql.=' AND p.category_id=?';$args[]=$category;}
    $sql.=' ORDER BY p.id DESC';$q=db()->prepare($sql);$q->execute($args);$products=$q->fetchAll();
    echo '<section class="hero mb-5"><div class="row align-items-center"><div class="col-lg-8"><div class="text-info small fw-bold mb-3">YOUR NEXT LEVEL STARTS HERE</div><h1>Gear up.<br><span class="text-info">Play harder.</span></h1><p class="muted fs-5 my-4">Discover games, accessories and tech essentials picked for your setup.</p><a class="btn btn-primary btn-lg" href="#products">Explore the store</a></div><div class="col-lg-4 text-center display-1">🎮</div></div></section><div id="products" class="d-flex justify-content-between flex-wrap gap-3 align-items-center mb-4"><h2>Featured products</h2><form class="d-flex gap-2"><input class="form-control" name="q" placeholder="Search..." value="'.e($search).'"><select class="form-select" name="category" onchange="this.form.submit()"><option value="0">All categories</option>';
    foreach($cats as $c) echo '<option value="'.$c['id'].'" '.($category==$c['id']?'selected':'').'>'.e($c['name']).'</option>';
    echo '</select><button class="btn btn-outline-light">Search</button></form></div><div class="row g-4">';
    foreach($products as $p) {
        echo '<div class="col-sm-6 col-lg-4 col-xl-3"><article class="product"><a href="?page=product&id='.$p['id'].'" class="text-decoration-none text-white"><div class="art">'.e($p['emoji']).'</div></a><div class="pbody"><small class="text-info">'.e($p['category']).'</small><h5 class="mt-2">'.e($p['name']).'</h5><div class="d-flex justify-content-between"><span class="price">'.money($p['price']).'</span><small class="'.($p['stock']?'text-success':'text-danger').'">'.($p['stock']?'In stock':'Sold out').'</small></div><form method="post" action="?page=add" class="mt-3">';csrf();echo '<input type="hidden" name="id" value="'.$p['id'].'"><button class="btn btn-primary w-100" '.(!$p['stock']?'disabled':'').'>Add to cart</button></form></div></article></div>';
    }
    if(!$products) echo '<p class="muted">No products found.</p>';
    echo '</div>';
}
elseif ($page === 'product') {
    $q=db()->prepare('SELECT p.*,c.name category FROM products p JOIN categories c ON c.id=p.category_id WHERE p.id=? AND p.active=1');$q->execute([(int)($_GET['id']??0)]);$p=$q->fetch();
    if(!$p) echo '<h2>Product not found.</h2>'; else {
        echo '<div class="row g-5 align-items-center"><div class="col-md-6"><div class="detailart">'.e($p['emoji']).'</div></div><div class="col-md-6"><small class="text-info">'.e($p['category']).'</small><h1 class="display-5 mt-2">'.e($p['name']).'</h1><div class="price fs-2 my-3">'.money($p['price']).'</div><p class="muted">'.nl2br(e($p['description'])).'</p><p class="text-success">'.$p['stock'].' available</p><form method="post" action="?page=add" class="d-flex gap-2">';csrf();echo '<input type="hidden" name="id" value="'.$p['id'].'"><input class="form-control" type="number" name="qty" min="1" max="'.max(1,$p['stock']).'" value="1" style="max-width:90px"><button class="btn btn-primary flex-grow-1" '.(!$p['stock']?'disabled':'').'>Add to cart</button></form></div></div>';
    }
}
elseif ($page === 'cart') {
    $items=cart_items(); echo '<h1 class="mb-4">Shopping cart</h1>';
    if(!$items) echo '<div class="panel text-center py-5"><div class="display-2">🛒</div><h3>Your cart is empty</h3><a href="?" class="btn btn-primary mt-3">Continue shopping</a></div>';
    else {
        echo '<div class="row g-4"><div class="col-lg-8"><form method="post" action="?page=update" class="panel">';csrf();echo '<div class="table-responsive"><table class="table"><tr><th>Product</th><th>Price</th><th>Quantity</th><th>Subtotal</th><th></th></tr>';
        foreach($items as $it){$p=$it['product'];echo '<tr><td>'.e($p['emoji'].' '.$p['name']).'</td><td>'.money($p['price']).'</td><td><input class="form-control" style="width:85px" type="number" min="0" max="'.$p['stock'].'" name="qty['.$p['id'].']" value="'.$it['qty'].'"></td><td>'.money($it['subtotal']).'</td><td><a class="text-danger" href="?page=remove&id='.$p['id'].'">Remove</a></td></tr>';}
        echo '</table></div><button class="btn btn-outline-light">Update cart</button></form></div><div class="col-lg-4"><div class="panel"><h4>Order summary</h4><p class="d-flex justify-content-between">Shipping <span class="text-success">Free</span></p><hr><h4 class="d-flex justify-content-between">Total <span>'.money(cart_total()).'</span></h4><a href="?page=checkout" class="btn btn-primary w-100 mt-3">Proceed to checkout</a></div></div></div>';
    }
}
elseif ($page === 'register') {
    echo '<div class="row justify-content-center"><div class="col-md-6 col-lg-5"><div class="panel"><h1>Create account</h1><form method="post">';csrf();echo '<label class="form-label">Full name</label><input class="form-control mb-3" name="name" required><label class="form-label">Email</label><input class="form-control mb-3" type="email" name="email" required><label class="form-label">Password (8+ characters)</label><input class="form-control mb-3" type="password" name="password" minlength="8" required><button class="btn btn-primary w-100">Create account</button></form><p class="mt-3 muted">Already registered? <a href="?page=login">Sign in</a></p></div></div></div>';
}
elseif ($page === 'login') {
    echo '<div class="row justify-content-center"><div class="col-md-6 col-lg-5"><div class="panel"><h1>Sign in</h1><form method="post">';csrf();echo '<label class="form-label">Email</label><input class="form-control mb-3" type="email" name="email" required><label class="form-label">Password</label><input class="form-control mb-3" type="password" name="password" required><button class="btn btn-primary w-100">Sign in</button></form><p class="mt-3 muted">No account? <a href="?page=register">Create one</a></p></div></div></div>';
}
elseif ($page === 'checkout') {
    require_login(); $items=cart_items();
    if(!$items) echo '<p>Your cart is empty. <a href="?">Return to store</a>.</p>';
    else {
        echo '<h1>Checkout</h1><div class="row g-4"><div class="col-md-7"><div class="panel"><p class="muted">Classroom demo only. No real payment is processed.</p><form method="post">';csrf();echo '<label class="form-label">Payment method</label><select class="form-select mb-3" name="payment"><option>PIX</option><option>Boleto</option><option>Card</option></select><button class="btn btn-primary w-100">Confirm order</button></form></div></div><div class="col-md-5"><div class="panel"><h4>Order summary</h4>';
        foreach($items as $it) echo '<p class="d-flex justify-content-between">'.e($it['product']['name']).' × '.$it['qty'].' <b>'.money($it['subtotal']).'</b></p>';
        echo '<hr><h4 class="d-flex justify-content-between">Total <span>'.money(cart_total()).'</span></h4></div></div></div>';
    }
}
elseif ($page === 'success') {
    require_login(); $id=(int)($_SESSION['last_order']??0);$q=db()->prepare('SELECT * FROM orders WHERE id=? AND user_id=?');$q->execute([$id,$_SESSION['user']['id']]);$order=$q->fetch();
    if(!$order) echo '<p>Order not found.</p>'; else echo '<div class="panel text-center mx-auto" style="max-width:650px"><div class="display-1">🎉</div><h1>Thank you for your purchase!</h1><p class="muted">Your order has been confirmed (payment simulation).</p><h4>Order #'.$order['id'].'</h4><p>Payment: '.e($order['payment_method']).'</p><h2>'.money($order['total']).'</h2><a class="btn btn-primary mt-3" href="?">Back to store</a></div>';
}
elseif ($page === 'admin') {
    $stats=db()->query('SELECT COUNT(*) products FROM products')->fetch();$orders=db()->query('SELECT COUNT(*) orders,COALESCE(SUM(total),0) revenue FROM orders')->fetch();
    echo '<h1>Admin dashboard</h1><div class="row g-3 my-3"><div class="col-md-4"><div class="stat">Products<strong class="d-block">'.$stats['products'].'</strong></div></div><div class="col-md-4"><div class="stat">Orders<strong class="d-block">'.$orders['orders'].'</strong></div></div><div class="col-md-4"><div class="stat">Revenue<strong class="d-block">'.money($orders['revenue']).'</strong></div></div></div><div class="d-flex justify-content-between"><h2>Products</h2><a class="btn btn-primary" href="?page=admin_form">Add product</a></div><div class="panel table-responsive mt-3"><table class="table"><tr><th>Product</th><th>Price</th><th>Stock</th><th>Visible</th><th></th></tr>';
    foreach(db()->query('SELECT * FROM products ORDER BY id DESC') as $p) echo '<tr><td>'.e($p['emoji'].' '.$p['name']).'</td><td>'.money($p['price']).'</td><td>'.$p['stock'].'</td><td>'.($p['active']?'Yes':'No').'</td><td><a class="btn btn-sm btn-outline-light" href="?page=admin_form&id='.$p['id'].'">Edit</a> <a class="btn btn-sm btn-outline-danger" href="?page=admin_'.($p['active']?'off':'on').'&id='.$p['id'].'">'.($p['active']?'Hide':'Show').'</a></td></tr>';
    echo '</table></div><h2 class="mt-4">Orders</h2><div class="panel table-responsive"><table class="table"><tr><th>Order</th><th>Customer</th><th>Payment</th><th>Total</th><th>Date</th></tr>';
    $q=db()->query('SELECT o.*,u.name FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.id DESC');
    foreach($q as $o) echo '<tr><td>#'.$o['id'].'</td><td>'.e($o['name']).'</td><td>'.e($o['payment_method']).'</td><td>'.money($o['total']).'</td><td>'.e($o['created_at']).'</td></tr>';
    echo '</table></div>';
}
elseif ($page === 'admin_form') {
    if(!admin()){http_response_code(403);exit('Admin access required.');}
    $id=(int)($_GET['id']??0);$q=db()->prepare('SELECT * FROM products WHERE id=?');$q->execute([$id]);$p=$q->fetch()?:[];
    echo '<h1>'.($id?'Edit product':'Add product').'</h1><div class="panel mt-3"><form method="post" action="?page=admin_save">';csrf();echo '<input type="hidden" name="id" value="'.$id.'"><label class="form-label">Product name</label><input class="form-control mb-3" name="name" required value="'.e($p['name']??'').'"><label class="form-label">Description</label><textarea class="form-control mb-3" name="description" rows="3">'.e($p['description']??'').'</textarea><div class="row"><div class="col"><label class="form-label">Price</label><input class="form-control mb-3" type="number" min=".01" step=".01" name="price" required value="'.e($p['price']??'').'"></div><div class="col"><label class="form-label">Stock</label><input class="form-control mb-3" type="number" min="0" name="stock" required value="'.e($p['stock']??0).'"></div><div class="col"><label class="form-label">Emoji</label><input class="form-control mb-3" name="emoji" value="'.e($p['emoji']??'🎮').'"></div></div><label class="form-label">Category</label><select class="form-select mb-3" name="category_id">';
    foreach(db()->query('SELECT * FROM categories') as $c) echo '<option value="'.$c['id'].'" '.(($p['category_id']??0)==$c['id']?'selected':'').'>'.e($c['name']).'</option>';
    echo '</select><button class="btn btn-primary">Save product</button> <a class="btn btn-outline-light" href="?page=admin">Cancel</a></form></div>';
}
else echo '<h1>Page not found</h1>';

if (!logged() && in_array($page,['home','product','cart'])) echo '<p class="text-center muted mt-4">New here? <a href="?page=register">Create an account</a> to checkout.</p>';
footer_html();
