<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="styles.css" />
    <title>Вход</title>
  </head>
  <body>
    <div class="container">
        <h1 class="system-tytle">Школьный анализатор успеваемости</h1>
      <div class="login-form__container">
        <form action="login.php" method="get" class="login-form">
          <div class="login-form__input">
            <input type="text" name="login" id="login" placeholder="Логин" required />
          </div>
          <div class="login-form__input">
            <input type="password" name="password" id="password" placeholder="Пароль" required />
          </div>
          <div class="login-form__submit-button">
            <input type="submit" value="Войти" />
          </div>
        </form>
      </div>
    </div>
    <script src="scripts.js"></script>
  </body>
</html>
