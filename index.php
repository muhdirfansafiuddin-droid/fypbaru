<!-- Dalam form login anda -->
<form action="login.php" method="POST">
    <div class="form-group">
        <label for="military_number">Military Number</label>
        <input type="text" class="form-control" id="military_number" 
               name="military_number" required placeholder="e.g., CD001">
    </div>
    <div class="form-group">
        <label for="password">Password</label>
        <input type="password" class="form-control" id="password" 
               name="password" required>
    </div>
    <button type="submit" class="btn btn-primary btn-block">Login</button>
</form>