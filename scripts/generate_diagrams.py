import urllib.request
import urllib.error
import base64
import json
import os

SIMPLIFIED_MERMAID = """classDiagram
    direction TB

    class User {
        +String id
        +String name
        +String email
        +UserRole role
    }

    class Admin {
        +manageUsers()
        +updateSettings()
    }

    class Vendor {
        +browseProducts()
        +placeOrder()
    }

    class Supplier {
        +manageProducts()
        +fulfillOrders()
        +viewAnalytics()
    }

    class Product {
        +String name
        +Float price
        +Int stockQuantity
        +String category
        +ProductStatus status
    }

    class Order {
        +String orderNumber
        +Float totalAmount
        +OrderStatus status
        +String deliveryAddress
    }

    class OrderItem {
        +Int quantity
        +Float priceAtOrder
    }

    class Notification {
        +String title
        +String message
        +Boolean isRead
    }

    User <|-- Admin
    User <|-- Vendor
    User <|-- Supplier

    Supplier "1" --> "0..*" Product : catalogs & stocks
    Vendor "1" --> "0..*" Order : places
    Supplier "1" --> "0..*" Order : receives & fulfills
    Order "1" *-- "1..*" OrderItem : contains
    Product "1" <-- "0..*" OrderItem : references
    User "1" --> "0..*" Notification : receives
"""

DETAILED_MERMAID = """classDiagram
    direction TB

    class UserRole {
        <<enumeration>>
        ADMIN
        VENDOR
        SUPPLIER
    }

    class ProductStatus {
        <<enumeration>>
        PENDING
        APPROVED
        DENIED
    }

    class OrderStatus {
        <<enumeration>>
        PENDING
        CONFIRMED
        DELIVERED
        CANCELLED
    }

    class DemandStatus {
        <<enumeration>>
        LOW
        MEDIUM
        HIGH
    }

    class User {
        #String id
        #String email
        #String passwordHash
        #String name
        #String businessName
        #String contactNumber
        #String address
        #UserRole role
        #String profileImage
        #Boolean isActive
        #Boolean twoFactorEnabled
        #Boolean notificationsEnabled
        #DateTime createdAt
        +login(email, password) Boolean
        +logout() void
        +updateProfile(data) Boolean
        +changePassword(oldPass, newPass) Boolean
        +enable2FA() Boolean
    }

    class Admin {
        +viewPlatformStats() StatsDTO
        +toggleUserStatus(userId, isActive) Boolean
        +reviewProduct(productId, status, reason) Boolean
        +updateSystemSettings(key, value) Boolean
    }

    class Vendor {
        +browseMarketplace(category, search) ListProduct
        +checkout(productId, quantity, deliveryDetails) Order
        +viewOrders() ListOrder
        +cancelOrder(orderId) Boolean
        +getSpendSummary() DashboardStats
    }

    class Supplier {
        +String paymayaNumber
        +addProduct(productDTO) Product
        +updateProduct(productId, productDTO) Boolean
        +deleteProduct(productId) Boolean
        +updateOrderStatus(orderId, newStatus) Boolean
        +uploadProductImage(imageFile) String
        +getSalesAnalytics() AnalyticsDTO
    }

    class Product {
        +Int id
        +String supplierId
        +String supplierName
        +String name
        +Float price
        +String description
        +Int stockQuantity
        +DemandStatus demandStatus
        +String category
        +String imageUrl
        +ProductStatus status
        +String rejectionReason
        +DateTime createdAt
        +isAvailable(requestedQty) Boolean
        +deductStock(qty) Boolean
        +restoreStock(qty) void
        +setStatus(status, reason) void
    }

    class Order {
        +Int id
        +String vendorId
        +String supplierId
        +Int supplierOrderNumber
        +Float totalAmount
        +OrderStatus status
        +String paymentMethod
        +String deliveryAddress
        +String deliveryNotes
        +DateTime createdAt
        +DateTime updatedAt
        +calculateSubtotal() Float
        +calculateDeliveryFee() Float
        +updateStatus(newStatus) Boolean
        +canCancel() Boolean
    }

    class OrderItem {
        +Int id
        +Int orderId
        +Int productId
        +String name
        +Float priceAtOrder
        +Int quantity
        +String imageUrl
        +getSubtotal() Float
    }

    class Notification {
        +Int id
        +String userId
        +String title
        +String message
        +String type
        +Boolean isRead
        +DateTime createdAt
        +markAsRead() void
    }

    class SecurityLog {
        +Int id
        +String userId
        +String ip
        +String action
        +String meta
        +DateTime createdAt
        +log(action, meta) void
    }

    class Setting {
        +String key
        +String value
        +DateTime updatedAt
        +get(key) String
        +set(key, value) void
    }

    User <|-- Admin
    User <|-- Vendor
    User <|-- Supplier

    Supplier "1" --> "0..*" Product : owns & manages
    Vendor "1" --> "0..*" Order : creates
    Supplier "1" --> "0..*" Order : receives
    Order "1" *-- "1..*" OrderItem : composed of
    Product "1" <-- "0..*" OrderItem : snapshots
    User "1" --> "0..*" Notification : receives
    User "1" --> "0..*" SecurityLog : generates
    Admin "1" ..> Setting : manages
"""

def generate_all():
    out_dir = os.path.join(os.path.dirname(__file__), "..", "diagrams")
    os.makedirs(out_dir, exist_ok=True)

    diagrams = [
        ("simplified-class-diagram", SIMPLIFIED_MERMAID, 1800),
        ("detailed-class-diagram", DETAILED_MERMAID, 2400)
    ]

    for name, code, width in diagrams:
        # 1. Save Raw Mermaid definition
        mmd_path = os.path.join(out_dir, f"{name}.mmd")
        with open(mmd_path, "w", encoding="utf-8") as f:
            f.write(code)
        print(f"[MMD] Saved: {mmd_path}")

        # 2. Generate SVG & PNG via mermaid.ink
        obj = {"code": code, "mermaid": {"theme": "default"}}
        encoded = base64.urlsafe_b64encode(json.dumps(obj).encode("utf-8")).decode("utf-8")

        # SVG
        svg_path = os.path.join(out_dir, f"{name}.svg")
        svg_url = f"https://mermaid.ink/svg/{encoded}"
        req = urllib.request.Request(svg_url, headers={"User-Agent": "Mozilla/5.0"})
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                data = resp.read()
                with open(svg_path, "wb") as f:
                    f.write(data)
                print(f"[SVG] Saved: {svg_path} ({len(data)} bytes)")
        except Exception as e:
            print(f"[SVG] Error {name}: {e}")

        # PNG
        png_path = os.path.join(out_dir, f"{name}.png")
        png_url = f"https://mermaid.ink/img/{encoded}?width={width}"
        req = urllib.request.Request(png_url, headers={"User-Agent": "Mozilla/5.0"})
        try:
            with urllib.request.urlopen(req, timeout=45) as resp:
                data = resp.read()
                with open(png_path, "wb") as f:
                    f.write(data)
                print(f"[PNG] Saved: {png_path} ({len(data)} bytes)")
        except Exception as e:
            print(f"[PNG] Error {name}: {e}")

    print("All diagrams generated successfully!")

if __name__ == "__main__":
    generate_all()

