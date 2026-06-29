<?php
enum Priority: int { case Low = 1; case High = 10; }
echo Priority::Low->value, ",", Priority::High->value;
