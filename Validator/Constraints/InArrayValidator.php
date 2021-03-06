<?php
/*
 * This file is part of the ecentria group, inc. software.
 *
 * (c) 2015, ecentria group, inc.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ecentria\Libraries\EcentriaRestBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * In array validator
 *
 * @author Sergey Chernecov <sergey.chernecov@intexsys.lv>
 */
class InArrayValidator extends ConstraintValidator
{
    /**
     * {@inheritdoc}
     */
    public function validate($value, Constraint $constraint)
    {
        if (!$constraint instanceof InArray) {
            throw new \Exception('This constraint must be instance of EcentriaRestBundle:InArray');
        }
        if (!in_array($value, $constraint->values)) {
            $this->context->addViolation(
                sprintf(
                    "Value '%s' is not supported. Possible values: \"'%s'\"",
                    $value,
                    implode('" , "', $constraint->values)
                )
            );
        }
    }
}
