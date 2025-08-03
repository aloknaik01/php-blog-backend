# Simple PHP Blog API

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

## License
MIT
