# Contributing to AirX

Thank you for your interest in contributing to **AirX**! AirX is an open-source clinical oxygen demand prediction and supply chain platform developed by [LifeBank](https://lifebank.ng). We welcome contributions from developers, data scientists, and healthcare supply chain specialists worldwide.

---

## 📜 Code of Conduct

All contributors and participants are expected to adhere to our [Code of Conduct](CODE_OF_CONDUCT.md). Please read it to ensure a respectful, inclusive, and collaborative environment.

---

## 🛠️ Getting Started

### Prerequisites

* **PHP**: `>= 7.3` (tested up to PHP `8.3`)
* **Composer**: `^2.0`
* **MySQL** or **MariaDB**: `5.7+` / `8.0+`
* **Extensions**: `pdo_mysql`, `json`, `mbstring`, `curl`

### Development Setup

1. **Fork & Clone**:
   ```bash
   git clone https://github.com/<your-username>/Airx.git
   cd Airx
   ```

2. **Install Dependencies**:
   ```bash
   composer install
   ```

3. **Configure Environment**:
   Copy the example environment configuration:
   ```bash
   cp .env.example .env
   ```
   Edit `.env` with your database credentials:
   ```env
   APP_ENV=development
   DB_HOST=localhost
   DB_USER=root
   DB_PASS=root
   DB_NAME=airx
   JWT_SECRET=your_minimum_16_character_secret_key
   AUTH_TOKEN=your_minimum_24_character_shared_auth_token
   OPENWEATHER_API_KEY=your_optional_openweather_api_key
   ```

4. **Initialize Database**:
   ```bash
   mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS airx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root -p airx < database/schema.sql
   mysql -u root -p airx < database/seeds.sql
   ```

5. **Run Tests**:
   ```bash
   vendor/bin/phpunit
   ```

---

## 🧪 Testing Guidelines

* All new features and bug fixes must include unit tests under `tests/`.
* We use **PHPUnit 9.6**.
* Run the test suite:
  ```bash
  vendor/bin/phpunit --testdox
  ```
* All existing and new tests must pass before opening a Pull Request.

---

## 📐 Coding Standards

* Follow **PSR-1**, **PSR-4**, and **PSR-12** coding style guidelines.
* **Security & Privacy**:
  * Never commit credentials, API keys, or personal access tokens.
  * Individual patient telemetry (such as patient names, conditions, or flow rates) must **never** be stored in the database.
  * Always use parameterized SQL queries with RedBeanPHP bindings (`?` or `:name`).
  * Enforce timing-safe string comparison (`hash_equals()`) for tokens.

---

## 🌿 Branching & Git Workflow

1. Create a descriptive feature branch from `main`:
   ```bash
   git checkout -b feat/your-feature-name
   # or
   git checkout -b fix/issue-description
   ```

2. Format commit messages using [Conventional Commits](https://www.conventionalcommits.org/):
   * `feat: add exponential smoothing model to predictor`
   * `fix: correct negative variance check in holdout validation`
   * `docs: update API endpoints in README`
   * `test: add unit test for hospital authentication rate limiter`

3. Keep commits atomic and self-contained.

---

## 🚀 Submitting a Pull Request

1. Push your branch to your GitHub fork:
   ```bash
   git push origin feat/your-feature-name
   ```
2. Open a Pull Request against the `main` branch of `lifebankng/Airx`.
3. Fill out the [Pull Request Template](.github/PULL_REQUEST_TEMPLATE.md) with details on:
   * The problem being solved
   * The approach taken
   * How it was tested
4. Address any code review feedback from maintainers.

---

## 🔒 Security Vulnerabilities

If you discover a security vulnerability in AirX, please **do not** open a public issue. Follow our responsible disclosure guidelines outlined in [SECURITY.md](SECURITY.md).
