<?php

declare(strict_types=1);

namespace Bolt\Twig\TokenParser;

use Bolt\Twig\Node\SetcontentNode;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Node;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

/**
 * Twig {% setcontent %} token parser.
 *
 * @author Bob den Otter <bob@twokings.nl>
 * @author Xiao-Hu Tai <xiao@twokings.nl>
 */
class SetcontentTokenParser extends AbstractTokenParser
{
    /**
     * {@inheritdoc}
     */
    public function __construct()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function parse(Token $token): Node
    {
        $lineno = $token->getLine();

        $arguments = new ArrayExpression([], $lineno);
        $whereArguments = [];

        // name - the new variable with the results
        $name = $this->parser->getStream()->expect(Token::NAME_TYPE)->getValue();
        $this->parser->getStream()->expect(Token::OPERATOR_TYPE, '=');

        // ContentType, or simple expression to content.
        $contentType = $this->parser->getExpressionParser()->parseExpression();

        $counter = 0;

        do {
            // where parameter
            if ($this->parser->getStream()->test(Token::NAME_TYPE, 'where')) {
                $this->parser->getStream()->next();
                $whereArguments = ['wherearguments' => $this->parser->getExpressionParser()->parseExpression()];
            }

            // limit parameter
            if ($this->parser->getStream()->test(Token::NAME_TYPE, 'limit')) {
                $this->parser->getStream()->next();
                $limit = $this->parser->getExpressionParser()->parseExpression();
                $arguments->addElement($limit, new ConstantExpression('limit', $lineno));
            }

            // order / orderby parameter
            if ($this->parser->getStream()->test(Token::NAME_TYPE, 'order') ||
                $this->parser->getStream()->test(Token::NAME_TYPE, 'orderby')) {
                $this->parser->getStream()->next();
                $order = $this->parser->getExpressionParser()->parseExpression();
                $arguments->addElement($order, new ConstantExpression('order', $lineno));
            }

            // paging / allowpaging parameter
            if ($this->parser->getStream()->test(Token::NAME_TYPE, 'paging') ||
                $this->parser->getStream()->test(Token::NAME_TYPE, 'allowpaging')) {
                $this->parser->getStream()->next();
                $arguments->addElement(
                    new ConstantExpression(true, $lineno),
                    new ConstantExpression('paging', $lineno)
                );
            }

            // printquery parameter
            if ($this->parser->getStream()->test(Token::NAME_TYPE, 'printquery')) {
                $this->parser->getStream()->next();
                $arguments->addElement(
                    new ConstantExpression(true, $lineno),
                    new ConstantExpression('printquery', $lineno)
                );
            }

            // returnsingle parameter
            if ($this->parser->getStream()->test(Token::NAME_TYPE, 'returnsingle')) {
                $this->parser->getStream()->next();
                $arguments->addElement(
                    new ConstantExpression(true, $lineno),
                    new ConstantExpression('returnsingle', $lineno)
                );
            }

            // Make sure we don't get stuck in a loop, if a token can't be parsed.
            ++$counter;
        } while (! $this->parser->getStream()->test(Token::BLOCK_END_TYPE) && ($counter < 10));

        $this->parser->getStream()->expect(Token::BLOCK_END_TYPE);

        return new SetcontentNode($name, $contentType, $arguments, $whereArguments, $lineno, $this->getTag());
    }

    /**
     * {@inheritdoc}
     */
    public function getTag(): string
    {
        return 'setcontent';
    }
}
