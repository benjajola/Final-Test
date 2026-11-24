CREATE DATABASE IF NOT EXISTS gamevault CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE gamevault;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('customer','admin') NOT NULL DEFAULT 'customer',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  description TEXT NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  stock INT UNSIGNED NOT NULL DEFAULT 0,
  emoji VARCHAR(16) NOT NULL DEFAULT '🎮',
  active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB;

CREATE TABLE orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  total DECIMAL(10,2) NOT NULL,
  payment_method ENUM('PIX','Boleto','Card') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

INSERT INTO categories(name) VALUES
('Consoles'),('Gaming Accessories'),('PC & Components'),('Games');

INSERT INTO products(category_id,name,description,price,stock,emoji) VALUES
(1,'NovaPlay Console','A next-generation gaming console designed for smooth gameplay and stunning visuals.',499.99,12,'🎮'),
(2,'Phantom Wireless Headset','Comfortable wireless headset with immersive sound and a clear microphone.',89.90,25,'🎧'),
(3,'Pulse Mechanical Keyboard','Compact mechanical keyboard with customizable RGB lighting.',119.00,18,'⌨️'),
(4,'Cyber Quest: Beyond','Explore a futuristic open world in this action-adventure game.',59.99,30,'🕹️'),
(2,'Velocity Gaming Mouse','Lightweight gaming mouse with adjustable sensitivity.',49.90,40,'🖱️'),
(3,'RTX Performance Graphics Card','High-performance graphics card for demanding games.',629.00,7,'🧩');
