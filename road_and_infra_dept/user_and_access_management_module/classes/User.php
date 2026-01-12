<?php
class User {
    private $conn;
    private $table_name = "users";
    
    public $id;
    public $email;
    public $password;
    public $first_name;
    public $middle_name;
    public $last_name;
    public $birthday;
    public $address;
    public $civil_status;
    public $role;
    public $status;
    public $id_photo_path;
    public $created_at;
    public $updated_at;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    // Create new user
    public function create() {
        $query = "INSERT INTO " . $this->table_name . "
                SET email=:email, password=:password, first_name=:first_name, 
                    middle_name=:middle_name, last_name=:last_name, birthday=:birthday,
                    address=:address, civil_status=:civil_status, role=:role, 
                    status='pending', id_photo_path=:id_photo_path";
        
        $stmt = $this->conn->prepare($query);
        
        // Sanitize inputs
        $this->email = htmlspecialchars(strip_tags($this->email));
        $this->password = password_hash($this->password, PASSWORD_DEFAULT);
        $this->first_name = htmlspecialchars(strip_tags($this->first_name));
        $this->middle_name = htmlspecialchars(strip_tags($this->middle_name));
        $this->last_name = htmlspecialchars(strip_tags($this->last_name));
        $this->address = htmlspecialchars(strip_tags($this->address));
        $this->civil_status = htmlspecialchars(strip_tags($this->civil_status));
        $this->role = htmlspecialchars(strip_tags($this->role));
        $this->id_photo_path = htmlspecialchars(strip_tags($this->id_photo_path));
        
        // Bind parameters
        $stmt->bindParam(":email", $this->email);
        $stmt->bindParam(":password", $this->password);
        $stmt->bindParam(":first_name", $this->first_name);
        $stmt->bindParam(":middle_name", $this->middle_name);
        $stmt->bindParam(":last_name", $this->last_name);
        $stmt->bindParam(":birthday", $this->birthday);
        $stmt->bindParam(":address", $this->address);
        $stmt->bindParam(":civil_status", $this->civil_status);
        $stmt->bindParam(":role", $this->role);
        $stmt->bindParam(":id_photo_path", $this->id_photo_path);
        
        if($stmt->execute()) {
            $this->id = $this->conn->lastInsertId();
            return true;
        }
        
        return false;
    }
    
    // User login
    public function login() {
        $query = "SELECT id, email, password, first_name, middle_name, last_name, 
                         role, status FROM " . $this->table_name . " 
                 WHERE email = :email AND status IN ('approved', 'active') LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":email", $this->email);
        $stmt->execute();
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if($row && password_verify($this->password, $row['password'])) {
            $this->id = $row['id'];
            $this->first_name = $row['first_name'];
            $this->middle_name = $row['middle_name'];
            $this->last_name = $row['last_name'];
            $this->role = $row['role'];
            $this->status = $row['status'];
            return true;
        }
        
        return false;
    }
    
    // Check if email exists
    public function emailExists() {
        $query = "SELECT id FROM " . $this->table_name . " WHERE email = :email LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":email", $this->email);
        $stmt->execute();
        
        return $stmt->rowCount() > 0;
    }
    
    // Get user by ID
    public function getById($user_id) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE id = :id LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $user_id);
        $stmt->execute();
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if($row) {
            $this->id = $row['id'];
            $this->email = $row['email'];
            $this->first_name = $row['first_name'];
            $this->middle_name = $row['middle_name'];
            $this->last_name = $row['last_name'];
            $this->birthday = $row['birthday'];
            $this->address = $row['address'];
            $this->civil_status = $row['civil_status'];
            $this->role = $row['role'];
            $this->status = $row['status'];
            $this->id_photo_path = $row['id_photo_path'];
            $this->created_at = $row['created_at'];
            $this->updated_at = $row['updated_at'];
            return true;
        }
        
        return false;
    }
    
    // Get pending users for approval
    public function getPendingUsers() {
        $query = "SELECT * FROM " . $this->table_name . " 
                 WHERE status = 'pending' ORDER BY created_at DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Update user status
    public function updateStatus($status, $approved_by = null) {
        $query = "UPDATE " . $this->table_name . " 
                 SET status = :status, updated_at = CURRENT_TIMESTAMP 
                 WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":status", $status);
        $stmt->bindParam(":id", $this->id);
        
        return $stmt->execute();
    }
    
    // Get all users
    public function getAllUsers() {
        $query = "SELECT id, email, first_name, middle_name, last_name, role, status, created_at 
                 FROM " . $this->table_name . " 
                 ORDER BY created_at DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Delete user
    public function delete() {
        $query = "DELETE FROM " . $this->table_name . " WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $this->id);
        
        return $stmt->execute();
    }
    
    // Update user role
    public function updateUserRole($user_id, $new_role) {
        $query = "UPDATE " . $this->table_name . " 
                 SET role = :role, updated_at = CURRENT_TIMESTAMP 
                 WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":role", $new_role);
        $stmt->bindParam(":id", $user_id);
        
        return $stmt->execute();
    }
}
?>
