# 📝 Blog API Backend (PHP + MySQL)

A simple yet robust **RESTful API backend** for a blog application, built with **PHP (no framework)** and **MySQL**.  
It supports full CRUD operations with global error handling, `.env` configuration, and organized folder structure.

![Blog API banner](https://i.imgur.com/vPPexmf.png)

---

## 🚀 What i made

- Create, read, update, and delete blog posts
- RESTful API design
- Global error handling and unified response structure
- `.env` for sensitive credentials
- Simple and clean folder organization
- Git version-controlled with feature branches

---

## ⚙️ Tech Stack

|           Layer | Tech                         |
| --------------: | ---------------------------- |
|         Backend | PHP (Raw)                    |
|        Database | MySQL                        |
|           Tools | XAMPP / Apache, Postman, Git |
| Version Control | Git + GitHub                 |

---

## 📂 Folder Structure

A lightweight RESTful API backend for a blog built using PHP and MySQL.

## Features

- GET all posts
- GET single post by ID
- Create a new post
- Update a post
- Delete a post

## Technologies

- PHP (vanilla)
- MySQL
- XAMPP
- REST API
- GitHub (Solo dev branching workflow)

## Setup

1. Clone this repo
2. Import `blog_db.sql` into phpMyAdmin
3. Serve via XAMPP (`htdocs/blog-app`)
4. Use Postman to test API endpoints

## API Endpoints

- `GET /get-posts.php`
- `GET /get-post.php?id=1`
- `POST /create-post.php`
- `PUT /update-post.php`
- `DELETE /delete-post.php`

---

## Solo Developer Workflow

- Branch per feature (feature/xyz)
- Commit, push, and PR to `main`

---


---

## 🔗 API Endpoints

| Method | Endpoint                                 | Description             |
|--------|------------------------------------------|-------------------------|
| GET    | `/posts/get-posts.php`                   | Get all posts           |
| GET    | `/posts/get-post-by-id.php?id=1`         | Get a single post       |
| POST   | `/posts/create-post.php`                 | Create a new post       |
| PUT    | `/posts/update-post.php?id=1`            | Update an existing post |
| DELETE | `/posts/delete-post.php?id=1`            | Delete a post           |

---

## 🛠 Setup Instructions

### 1. Clone the repo

```bash
git clone https://github.com/aloknaik01/php-blog-backend.git
cd blog-app
```

## License

MIT
