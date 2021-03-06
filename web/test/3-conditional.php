# Summary: Conditional objects allow for code branching. Only the
# first matching condition is executed.

title: "Conditional", @import "test.php"

# Basic conditional object

a: 7

"<p>Variable a is " {a ? < 4: "less than", = 4: "equal to", > 4: "greater than"} " 4!</p>" .print

_flush_()

# Using a catch all condition *:

name: @null

"<p>Dear " {? name: name, *: "visitor"} ", welcome to SimplifiedPHP. " .print

name: "Dr. Horrible"

"I'll just call you " {? name: name, *: "visitor"} " &mdash; if that's alright with you.</p>" .print

_flush_()

# Using conditions with iteration

'<p>'.print

desires: ["snail", "cookie", "rusty nail", "burger", "milkshake", "vulture"]

desires @ {
  ? it.contains "nail": @continue

  "I " {? key > 1: "also"} " want a " it {
    it = ? "cookie": " and a glass of milk"
           "burger": " with mustard"
        "milkshake": " to bring all the boys to the yard"
  } ". ".print

  ? it.contains "shake": @break
}

'</p>'.print
