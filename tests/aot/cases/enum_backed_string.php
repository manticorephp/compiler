<?php
enum Status: string {
    case Active = "active";
    case Inactive = "inactive";
}
echo Status::Active->name, "=", Status::Active->value, ",", Status::Inactive->value;
