# AirClass API

A RESTful API for managing virtual classrooms, attendance tracking, and educational resources.

## Features

-   **Authentication & Authorization**

    -   JWT-based authentication
    -   Role-based access control (student, teacher, admin)
    -   Secure password handling

-   **Classroom Management**

    -   Create and manage virtual classrooms
    -   Real-time classroom status tracking
    -   Teacher-classroom assignment
    -   IP and port management for classroom connections

-   **Attendance System**

    -   Generate time-limited attendance codes
    -   Mark attendance with unique codes
    -   Track attendance per classroom and session
    -   Attendance history and reporting

-   **Resource Management**

    -   Upload and manage classroom images
    -   Handle presentation slides
    -   Secure file storage and retrieval

-   **Student Support**
    -   Request system for questions and clarifications
    -   Real-time help requests
    -   Request status tracking (pending, approved, rejected)

## API Documentation

The API documentation is available at `/docs` endpoint. It provides:

-   Detailed endpoint descriptions
-   Request/response schemas
-   Authentication requirements
-   Interactive API testing interface

## Requirements

-   PHP 7.4 or higher
-   MySQL 5.7 or higher
-   Apache/Nginx web server
-   mod_rewrite enabled (for Apache)
-   JWT support

## Installation

1. Clone the repository:

    ```bash
    git clone https://github.com/yourusername/airclass-api.git
    cd airclass-api
    ```

2. Create a `.env` file from the template:

    ```bash
    cp .env.example .env
    ```

3. Configure your environment variables in `.env`:

    ```
    DB_HOST=localhost
    DB_USER=your_db_user
    DB_PASS=your_db_password
    DB_NAME=airclass
    JWT_SECRET=your_jwt_secret
    ```

4. Create the database and run migrations:

    ```bash
    mysql -u your_db_user -p your_db_name < database/schema.sql
    ```

5. Set proper permissions:

    ```bash
    chmod 755 -R .
    chmod 777 -R uploads/
    ```

6. Configure your web server to point to the project directory

## Directory Structure

```
airclass-api/
├── docs/               # API documentation
├── inc/               # Core includes
│   ├── config.php     # Configuration
│   └── BaseController.php
├── modules/           # Feature modules
│   ├── auth/         # Authentication
│   ├── classroom/    # Classroom management
│   ├── attendance/   # Attendance system
│   ├── image/        # Image handling
│   ├── slide/        # Slide management
│   └── request/      # Student requests
├── uploads/          # Uploaded files
│   ├── images/      # Classroom images
│   └── slides/      # Presentation slides
├── database/         # Database files
│   └── schema.sql   # Database schema
├── .env             # Environment variables
├── .htaccess        # Apache configuration
├── index.php        # Entry point
└── openapi.yaml     # API specification
```

## API Endpoints

### Authentication

-   `POST /auth/login` - User login
-   `POST /auth/register` - User registration

### Classrooms

-   `GET /classroom` - List classrooms
-   `POST /classroom` - Create classroom
-   `PUT /classroom` - Update classroom
-   `DELETE /classroom` - Delete classroom

### Attendance

-   `GET /attendance` - List attendance records
-   `POST /attendance` - Mark attendance
-   `POST /attendance/code` - Generate attendance code

### Resources

-   `GET /image` - List images
-   `POST /image` - Upload image
-   `GET /slide` - List slides
-   `POST /slide` - Upload slide

### Requests

-   `GET /request` - List requests
-   `POST /request` - Create request
-   `PUT /request` - Update request
-   `DELETE /request` - Delete request

## Security

-   All endpoints (except login/register) require JWT authentication
-   Passwords are hashed using bcrypt
-   Input validation and sanitization
-   CORS protection
-   Rate limiting (configurable)
-   Secure file upload handling

## Error Handling

The API uses standard HTTP status codes and returns JSON responses in the following format:

```json
{
    "status": false,
    "message": "Error message",
    "debug": {
        "file": "error_file.php",
        "line": 123
    }
}
```

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Support

For support, email support@airclass.com or create an issue in the repository.
